use super::{Plan, Plugin, Resource, Result, acquire, archive, invalid};
use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;
use std::path::{Path, PathBuf};

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields, rename_all = "camelCase")]
pub struct Dependency {
    pub slug: String,
    pub version: String,
    pub plugin_data: Resource,
    pub sha256: String,
    pub requires: Vec<String>,
    pub tree_sha256: String,
}

#[derive(Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Lock {
    version: u32,
    plugins: Vec<Dependency>,
}

/// Owns temporary plugin trees. Keep alive until the canonical assembler finishes.
/// A3 consumes `trees()` and `dependencies()`, and persists `lock_bytes()` only on success.
pub struct Prepared {
    _stage: tempfile::TempDir,
    trees: Vec<StagedPlugin>,
    previous: BTreeMap<String, Dependency>,
}

pub struct StagedPlugin {
    pub directory: PathBuf,
    pub archive: PathBuf,
    pub dependency: Dependency,
}

impl Prepared {
    /// All resolution must finish before acquisition; all acquisition finishes before publication.
    pub fn acquire(plan: &Plan, lock_path: &Path, cache: &Path) -> Result<Self> {
        Self::acquire_using(plan, lock_path, cache, Resource::url)
    }

    // Separate resource naming from transport so local HTTP fixtures exercise the same locking path.
    fn acquire_using(
        plan: &Plan,
        lock_path: &Path,
        cache: &Path,
        resource_url: impl Fn(&Resource) -> String,
    ) -> Result<Self> {
        let previous = match std::fs::read(lock_path) {
            Ok(bytes) => {
                let lock: Lock = serde_json::from_slice(&bytes)
                    .map_err(|e| invalid(format!("invalid WordPress plugin lock: {e}")))?;
                if lock.version != 1 {
                    return Err(invalid("unsupported WordPress plugin lock version"));
                }
                let mut previous = BTreeMap::new();
                for p in lock.plugins {
                    // Lockfiles are input too; validate identities, versions, hashes and dependency edges.
                    let spec = Plugin {
                        slug: p.slug.clone(),
                        version: p.version.clone(),
                        plugin_data: Some(p.plugin_data.clone()),
                        sha256: Some(p.sha256.clone()),
                        requires: p.requires.clone(),
                    };
                    spec.validate()?;
                    if p.tree_sha256.len() != 64
                        || !p.tree_sha256.bytes().all(|b| b.is_ascii_hexdigit())
                        || previous.insert(p.slug.clone(), p).is_some()
                    {
                        return Err(invalid("invalid or duplicate plugin lock entry"));
                    }
                }
                previous
            }
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => BTreeMap::new(),
            Err(e) => return Err(e),
        };
        let stage = tempfile::tempdir()?;
        let mut trees = Vec::new();
        for plugin in &plan.plugins {
            let locked = previous
                .get(&plugin.slug)
                .filter(|p| p.version == plugin.version && p.plugin_data == plugin.resource());
            if let (Some(authored), Some(locked)) = (&plugin.sha256, locked)
                && authored != &locked.sha256
            {
                return Err(invalid(format!(
                    "plugin {}: authored sha256 conflicts with lock; explicitly remove the old lock entry to repin",
                    plugin.slug
                )));
            }
            let pin = plugin.sha256.as_deref().or_else(|| locked.map(|p| p.sha256.as_str()));
            let (zip, sha256) = acquire(plugin, pin, cache, &resource_url(&plugin.resource()))?;
            let directory = stage.path().join(&plugin.slug);
            archive::extract(&zip, &directory, plugin)?;
            let tree_sha256 = archive::tree_digest(&directory)?;
            let mut requires = plugin.requires.clone();
            requires.sort();
            trees.push(StagedPlugin {
                directory,
                archive: zip,
                dependency: Dependency {
                    slug: plugin.slug.clone(),
                    version: plugin.version.clone(),
                    plugin_data: plugin.resource(),
                    sha256,
                    requires,
                    tree_sha256,
                },
            });
        }
        Ok(Self {
            _stage: stage,
            trees,
            previous,
        })
    }

    pub fn trees(&self) -> &[StagedPlugin] {
        &self.trees
    }

    pub fn dependencies(&self) -> impl Iterator<Item = &Dependency> {
        self.trees().iter().map(|p| &p.dependency)
    }

    pub fn lock_bytes(&self) -> Result<Vec<u8>> {
        let mut bytes = serde_json::to_vec_pretty(&Lock {
            version: 1,
            plugins: self.dependencies().cloned().collect(),
        })?;
        bytes.push(b'\n');
        Ok(bytes)
    }

    /// Call only on the assembler's private staging tree. Existing unmanaged/local changes fail.
    /// Unrelated plugin directories survive, including formerly declared plugins (no implicit uninstall).
    pub fn install_into(&self, staged_plugins: &Path) -> Result<()> {
        std::fs::create_dir_all(staged_plugins)?;
        for tree in self.trees() {
            if !tree.archive.is_file() {
                return Err(invalid("staged plugin archive is missing"));
            }
            let dest = staged_plugins.join(&tree.dependency.slug);
            // Include case collisions even on a case-sensitive build host.
            for entry in std::fs::read_dir(staged_plugins)? {
                let entry = entry?;
                let name = entry.file_name();
                if name.to_string_lossy().eq_ignore_ascii_case(&tree.dependency.slug)
                    && name != tree.dependency.slug.as_str()
                {
                    return Err(invalid(format!(
                        "plugin {}: case-colliding output path",
                        tree.dependency.slug
                    )));
                }
            }
            if std::fs::symlink_metadata(&dest).is_ok() {
                let previous = self.previous.get(&tree.dependency.slug);
                let meta = std::fs::symlink_metadata(&dest)?;
                if !meta.is_dir()
                    || previous
                        .is_none_or(|p| archive::tree_digest(&dest).ok().as_deref() != Some(p.tree_sha256.as_str()))
                {
                    return Err(invalid(format!(
                        "plugin {}: output collision with unmanaged or locally modified plugin",
                        tree.dependency.slug
                    )));
                }
                std::fs::remove_dir_all(&dest)?;
            }
            archive::copy_tree(&tree.directory, &dest)?;
        }
        Ok(())
    }
}

/// Copy existing output into the canonical assembler's private staging tree.
pub fn copy_output(from: &Path, to: &Path) -> Result<()> {
    archive::copy_tree(from, to)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::{Read, Write};

    #[test]
    fn wordpress_org_first_acquisition_records_a_pin_and_reuses_it_offline() {
        let temp = tempfile::tempdir().unwrap();
        let mut writer = zip::ZipWriter::new(std::io::Cursor::new(Vec::new()));
        writer
            .start_file("example/example.php", zip::write::SimpleFileOptions::default())
            .unwrap();
        writer
            .write_all(b"<?php\n/*\nPlugin Name: Example\nVersion: 1.0\n*/\n")
            .unwrap();
        let bytes = writer.finish().unwrap().into_inner();
        let expected_sha = crate::wp_plugins::digest(&bytes);
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let url = format!("http://{}/example.1.0.zip", listener.local_addr().unwrap());
        let server = std::thread::spawn(move || {
            let (mut conn, _) = listener.accept().unwrap();
            conn.set_read_timeout(Some(std::time::Duration::from_secs(10))).unwrap();
            let mut request = Vec::new();
            let mut byte = [0];
            while conn.read(&mut byte).unwrap() != 0 {
                request.push(byte[0]);
                if request.ends_with(b"\r\n\r\n") {
                    break;
                }
            }
            write!(
                conn,
                "HTTP/1.1 200 OK\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
                bytes.len()
            )
            .unwrap();
            conn.write_all(&bytes).unwrap();
        });
        let config = crate::wp_plugins::parse(serde_json::json!({"example":"1.0"})).unwrap();
        let plan = crate::wp_plugins::resolve(&config, &[]).unwrap();
        let lock = temp.path().join("plugins.lock.json");
        let cache = temp.path().join("cache");
        let first = Prepared::acquire_using(&plan, &lock, &cache, |_| url.clone()).unwrap();
        server.join().unwrap();
        assert_eq!(first.trees()[0].dependency.sha256, expected_sha);
        assert_eq!(
            first.trees()[0].dependency.plugin_data,
            Resource::WordPress {
                slug: "example.1.0.zip".into()
            }
        );
        assert!(first.trees()[0].directory.join("example.php").is_file());
        let bytes = first.lock_bytes().unwrap();
        std::fs::write(&lock, &bytes).unwrap();
        let second = Prepared::acquire_using(&plan, &lock, &cache, |_| url.clone()).unwrap();
        assert_eq!(second.lock_bytes().unwrap(), bytes);
        assert_eq!(
            std::fs::read(first.trees()[0].directory.join("example.php")).unwrap(),
            std::fs::read(second.trees()[0].directory.join("example.php")).unwrap()
        );
        std::fs::write(cache.join(format!("{expected_sha}.zip")), b"changed bytes").unwrap();
        let failure = Prepared::acquire_using(&plan, &lock, &cache, |_| url.clone());
        assert!(failure.err().unwrap().to_string().contains("corrupt or unsafe cache"));
    }
}
