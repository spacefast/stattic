//! Shared parsing for synced files in source bundles and the CLI.
use super::front_matter;
use serde_json::{Map, Value};

type Parsed = Result<(Map<String, Value>, Option<String>), (usize, String)>;

pub fn md_record(text: &str) -> Parsed {
    let doc = front_matter::parse_document(text).map_err(|e| (e.line, e.message))?;
    Ok((doc.front, (!doc.body.is_empty()).then_some(doc.body)))
}

pub fn json_record(text: &str, content: bool) -> Parsed {
    let value: Value = serde_json::from_str(text).map_err(|e| (e.line(), e.to_string()))?;
    let Value::Object(mut map) = value else {
        return Err((1, "a sync file holds one JSON object".into()));
    };
    // Without a content column, `body` is an ordinary field and stays among the fields.
    if !content {
        return Ok((map, None));
    }
    let body = match map.shift_remove("body") {
        Some(Value::String(s)) if !s.is_empty() => Some(s),
        None | Some(Value::Null) | Some(Value::String(_)) => None,
        Some(_) => return Err((1, "body must be a string or null".into())),
    };
    Ok((map, body))
}

/// Where a file of a folder-tree sync (`content/docs/**/*.md`) sits in its kind's tree, from its
/// path below the sync directory (`guides/install.md`): its slug, and the file that holds its parent.
///
/// - `install.md` at the top is a top-level item, slug `install`;
/// - `guides/index.md` is the item for the folder: slug `guides`, parent the folder above's index;
/// - `guides/install.md` is a child of `guides/index.md`.
///
/// - `guides/zr_….md`, a file named by its uid, is a draft with no slug yet (slug `""`).
///
/// The top folder has no index file: it is the kind itself, whose landing page is an app page.
pub fn tree_place(rel: &str, format: &str) -> Result<(String, Option<String>), String> {
    let parts: Vec<&str> = rel.split('/').collect();
    let (file, folders) = parts.split_last().expect("a path has a file name");
    let stem = file.strip_suffix(&format!(".{format}")).unwrap_or(file);
    let index = stem == "index";
    if index && folders.is_empty() {
        return Err(format!(
            "index.{format} at the top of the sync folder has no folder to stand for; the landing page of the kind is an app page (sp/app/<path>/page.tsx)"
        ));
    }
    let slug = if index { folders[folders.len() - 1] } else { stem };
    // A draft started on the site has no slug yet, so its file is named by its uid: no slug.
    if !index && crate::sync::uid::valid(stem) {
        let above = (!folders.is_empty()).then(|| format!("{}/index.{format}", folders.join("/")));
        return Ok((String::new(), above));
    }
    let valid = |s: &str| {
        !s.is_empty()
            && s.len() <= 200
            && !s.starts_with('-')
            && !s.ends_with('-')
            && !s.contains("--")
            && s.bytes()
                .all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
    };
    for name in folders.iter().chain(std::iter::once(&slug)) {
        if !valid(name) {
            return Err(format!(
                "`{name}` isn't a slug: name files and folders with lowercase letters, digits and single hyphens"
            ));
        }
    }
    let above = if index { &folders[..folders.len() - 1] } else { folders };
    let parent = (!above.is_empty()).then(|| format!("{}/index.{format}", above.join("/")));
    Ok((slug.to_string(), parent))
}

/// The path below the sync directory a record of a folder-tree sync belongs at, from its slug and
/// its ancestors' slugs (nearest last), and whether other records sit below it: an item with
/// children is its folder's index file.
pub fn tree_path(ancestors: &[&str], slug: &str, has_children: bool, format: &str) -> String {
    let mut out: Vec<&str> = ancestors.to_vec();
    if has_children {
        out.push(slug);
        format!("{}/index.{format}", out.join("/"))
    } else {
        out.push(slug);
        let (last, dirs) = out.split_last().expect("one slug at least");
        if dirs.is_empty() {
            format!("{last}.{format}")
        } else {
            format!("{}/{last}.{format}", dirs.join("/"))
        }
    }
}

#[cfg(test)]
mod tree_tests {
    use super::*;

    #[test]
    fn a_files_folder_is_its_parent_and_an_index_file_is_its_folder() {
        assert_eq!(tree_place("install.md", "md").unwrap(), ("install".into(), None));
        assert_eq!(tree_place("guides/index.md", "md").unwrap(), ("guides".into(), None));
        assert_eq!(
            tree_place("guides/install.md", "md").unwrap(),
            ("install".into(), Some("guides/index.md".into()))
        );
        assert_eq!(
            tree_place("guides/deploy/index.md", "md").unwrap(),
            ("deploy".into(), Some("guides/index.md".into()))
        );
        assert!(tree_place("index.md", "md").is_err());
        assert!(tree_place("Guides/install.md", "md").unwrap_err().contains("`Guides`"));
        assert!(tree_place("guides/My Page.md", "md").is_err());
        assert_eq!(
            tree_place("guides/zr_0123456789abcdef0123.md", "md").unwrap(),
            (String::new(), Some("guides/index.md".into()))
        );
    }

    #[test]
    fn a_records_path_is_its_ancestors_and_an_index_file_when_it_has_children() {
        assert_eq!(tree_path(&[], "install", false, "md"), "install.md");
        assert_eq!(tree_path(&[], "guides", true, "md"), "guides/index.md");
        assert_eq!(tree_path(&["guides"], "install", false, "md"), "guides/install.md");
        assert_eq!(tree_path(&["guides"], "deploy", true, "md"), "guides/deploy/index.md");
        for rel in [
            "install.md",
            "guides/index.md",
            "guides/install.md",
            "guides/deploy/index.md",
        ] {
            let (slug, parent) = tree_place(rel, "md").unwrap();
            let ancestors: Vec<&str> = parent
                .as_deref()
                .map(|p| p.trim_end_matches("/index.md").split('/').collect())
                .unwrap_or_default();
            assert_eq!(tree_path(&ancestors, &slug, rel.ends_with("index.md"), "md"), rel);
        }
    }
}
