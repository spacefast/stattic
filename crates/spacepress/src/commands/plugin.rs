//! `spacepress plugin [--out <file>]`: write the SpacePress WordPress plugin as a ZIP to upload in wp-admin.
//!
//! The plugin (runtime/spacepress-plugin/) is the same for every app: it checks and installs what SpacePress
//! needs, receives deployments from `spacepress push`, and mounts the live build through SpacePress's loader.
//! Its version and platform pins are this compiler's.
use crate::app_plugin::substitute;
use crate::platform;
use std::io::Write as _;
use std::path::{Path, PathBuf};
use std::process::ExitCode;

const USAGE: &str = "usage: spacepress plugin [--out <file.zip>]";
/// The plugin's directory in wp-content/plugins.
pub(crate) const SLUG: &str = "spacepress";

/// The embedded plugin files by content, with this compiler's version and pins: the same plugin
/// has the same hash wherever it is written.
pub(crate) fn hash() -> String {
    use sha2::Digest as _;
    let mut hash = sha2::Sha256::new();
    let mut files: Vec<_> = crate::emit::embedded("spacepress-plugin/").collect();
    files.sort();
    for (path, bytes) in files {
        hash.update(path.as_bytes());
        hash.update([0]);
        hash.update((bytes.len() as u64).to_be_bytes());
        hash.update(bytes);
    }
    hash.update(env!("CARGO_PKG_VERSION").as_bytes());
    hash.update(platform::requirements().to_string().as_bytes());
    hash.finalize().iter().map(|b| format!("{b:02x}")).collect()
}

/// The plugin's files, as (path below the plugins directory, bytes).
pub(crate) fn files() -> Vec<(String, Vec<u8>)> {
    let vars = [
        ("spVersion", env!("CARGO_PKG_VERSION").to_string()),
        ("wpVersion", platform::WORDPRESS.to_string()),
        ("phpVersion", platform::PHP.to_string()),
        ("pluginHash", hash()),
    ];
    let mut files: Vec<(String, Vec<u8>)> = crate::emit::embedded("spacepress-plugin/")
        .map(|(rest, bytes)| {
            let bytes = if matches!(rest, "spacepress.php" | "readme.txt") {
                substitute(&String::from_utf8_lossy(bytes), &vars).into_bytes()
            } else {
                bytes.to_vec()
            };
            (format!("{SLUG}/{rest}"), bytes)
        })
        .collect();
    files.push((
        format!("{SLUG}/requirements.json"),
        (serde_json::to_string_pretty(&platform::requirements()).expect("JSON") + "\n").into_bytes(),
    ));
    files.push((
        format!("{SLUG}/LICENSE"),
        crate::emit::runtime_file("SpacePress/LICENSE")
            .expect("embedded license")
            .to_vec(),
    ));
    files.sort();
    files
}

/// The plugin as the ZIP wp-admin's Upload Plugin takes.
pub(crate) fn archive() -> std::io::Result<Vec<u8>> {
    let files = files();
    zip(files.iter().map(|(p, b)| (p.as_str(), b.as_slice())), true)
}

/// Seconds since the Unix epoch as a UTC date and time (Howard Hinnant's civil-from-days).
fn civil(seconds: u64) -> (i64, u8, u8, u8, u8, u8) {
    let (days, rest) = ((seconds / 86_400) as i64, seconds % 86_400);
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let day_of_era = z.rem_euclid(146_097);
    let year_of_era = (day_of_era - day_of_era / 1_460 + day_of_era / 36_524 - day_of_era / 146_096) / 365;
    let day_of_year = day_of_era - (365 * year_of_era + year_of_era / 4 - year_of_era / 100);
    let month_index = (5 * day_of_year + 2) / 153;
    let day = day_of_year - (153 * month_index + 2) / 5 + 1;
    let month = if month_index < 10 {
        month_index + 3
    } else {
        month_index - 9
    };
    (
        year_of_era + era * 400 + i64::from(month <= 2),
        month as u8,
        day as u8,
        (rest / 3_600) as u8,
        (rest % 3_600 / 60) as u8,
        (rest % 60) as u8,
    )
}

/// Now, as a ZIP timestamp (UTC). The plugin's files are replaced in place on a site, and PHP's
/// opcode cache keeps a file whose modification time did not change, so they can't all carry the
/// format's default of 1980-01-01.
fn now() -> Option<zip::DateTime> {
    let seconds = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .ok()?
        .as_secs();
    let (year, month, day, hour, minute, second) = civil(seconds);
    zip::DateTime::from_date_and_time(u16::try_from(year).ok()?, month, day, hour, minute, second).ok()
}

/// A ZIP of `files` (paths with `/`). `stamped` gives every entry the current time; without it they
/// carry the format's fixed default, so the same files make the same archive.
pub(crate) fn zip<'a>(files: impl IntoIterator<Item = (&'a str, &'a [u8])>, stamped: bool) -> std::io::Result<Vec<u8>> {
    let mut writer = zip::ZipWriter::new(std::io::Cursor::new(Vec::new()));
    let mut options = zip::write::SimpleFileOptions::default().compression_method(zip::CompressionMethod::Deflated);
    if let Some(now) = stamped.then(now).flatten() {
        options = options.last_modified_time(now);
    }
    for (path, bytes) in files {
        writer.start_file(path, options).map_err(std::io::Error::other)?;
        writer.write_all(bytes)?;
    }
    Ok(writer.finish().map_err(std::io::Error::other)?.into_inner())
}

pub(crate) fn cli(args: &[String]) -> ExitCode {
    let out = match args {
        [] => PathBuf::from("spacepress.zip"),
        [flag, path] if flag == "--out" => PathBuf::from(path),
        _ => {
            eprintln!("{USAGE}");
            return ExitCode::from(2);
        }
    };
    match write(&out) {
        Ok(()) => {
            println!(
                "wrote {} (SpacePress {})\nIn wp-admin: Plugins, Add Plugin, Upload Plugin, choose this file, then activate SpacePress.\nThen, in your project: spacepress login <site-url> and spacepress push",
                out.display(),
                env!("CARGO_PKG_VERSION")
            );
            ExitCode::SUCCESS
        }
        Err(e) => {
            eprintln!("{}: {e}", out.display());
            ExitCode::from(1)
        }
    }
}

fn write(out: &Path) -> std::io::Result<()> {
    crate::sync::write_atomic(out, &archive()?).map_err(std::io::Error::other)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Read as _;

    fn entries(bytes: Vec<u8>) -> Vec<(String, Vec<u8>)> {
        let mut archive = zip::ZipArchive::new(std::io::Cursor::new(bytes)).unwrap();
        (0..archive.len())
            .map(|i| {
                let mut entry = archive.by_index(i).unwrap();
                let mut bytes = Vec::new();
                entry.read_to_end(&mut bytes).unwrap();
                let name = entry.name().unwrap().into_owned();
                (name, bytes)
            })
            .collect()
    }

    #[test]
    fn the_plugin_archive_is_one_directory_with_every_placeholder_filled() {
        let entries = entries(archive().unwrap());
        assert!(entries.iter().any(|(name, _)| name == "spacepress/spacepress.php"));
        for (name, bytes) in &entries {
            assert!(
                name.starts_with("spacepress/"),
                "{name} is outside the plugin's directory"
            );
            assert!(
                !String::from_utf8_lossy(bytes).contains("{{"),
                "{name} still has a placeholder"
            );
        }
    }

    #[test]
    fn the_plugin_pins_what_the_platform_fixture_pins() {
        let entries = entries(archive().unwrap());
        let (_, bytes) = entries
            .iter()
            .find(|(name, _)| name == "spacepress/requirements.json")
            .unwrap();
        let shipped: serde_json::Value = serde_json::from_slice(bytes).unwrap();
        let fixture: serde_json::Value =
            serde_json::from_str(include_str!("../../tests/fixtures/platform.json")).unwrap();
        assert_eq!(shipped, fixture);
    }

    #[test]
    fn the_plugin_hash_is_the_same_for_the_same_files() {
        assert_eq!(hash(), hash());
        assert_eq!(hash().len(), 64);
    }

    #[test]
    fn stamped_entries_carry_the_current_date_and_unstamped_ones_do_not() {
        let year = |bytes: Vec<u8>| {
            let mut archive = zip::ZipArchive::new(std::io::Cursor::new(bytes)).unwrap();
            archive.by_index(0).unwrap().last_modified().map(|t| t.year())
        };
        let this_year = civil(
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_secs(),
        )
        .0;
        assert_eq!(
            year(zip([("a.txt", b"a".as_slice())], true).unwrap()).map(i64::from),
            Some(this_year)
        );
        assert_ne!(
            year(zip([("a.txt", b"a".as_slice())], false).unwrap()).map(i64::from),
            Some(this_year)
        );
        // The same files make the same unstamped archive: a tree's upload can be resumed by checksum.
        assert_eq!(
            zip([("a.txt", b"a".as_slice())], false).unwrap(),
            zip([("a.txt", b"a".as_slice())], false).unwrap()
        );
    }

    #[test]
    fn seconds_become_calendar_dates() {
        assert_eq!(civil(0), (1970, 1, 1, 0, 0, 0));
        assert_eq!(civil(951_782_399), (2000, 2, 28, 23, 59, 59));
        assert_eq!(civil(951_782_400), (2000, 2, 29, 0, 0, 0));
        assert_eq!(civil(1_709_251_199), (2024, 2, 29, 23, 59, 59));
        assert_eq!(civil(1_709_251_200), (2024, 3, 1, 0, 0, 0));
        assert_eq!(civil(4_102_444_800), (2100, 1, 1, 0, 0, 0));
    }
}
