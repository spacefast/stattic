//! `spacepress add <module> [dir]`: adds an optional part of SpacePress to a project as ordinary files the
//! author then owns. A module is a row in [`MODULES`]: its name, what it is, and the files it
//! writes. A new module is one more row and its files under `add/<name>/`.
//!
//! The command only ever creates files. One that is already there is kept as it is, so running it
//! again adds whatever is missing and changes nothing else. The project is compiled in memory
//! before the first write and again after the last: a project that doesn't compile is left alone,
//! and files that would break one that did (an export name the app already uses) are taken out
//! again.

use crate::diag::{self, Diag};
use crate::schema::Schema;
use std::io::Write as _;
use std::path::{Path, PathBuf};
use std::process::ExitCode;

/// When a file belongs in the project, beyond its path being free.
enum When {
    Always,
    /// An example of the module's own kinds: only for a project that has no `table()` yet, so an
    /// author who moved or renamed the example doesn't get it back.
    NoTables,
    NoStore,
    Shop,
    /// Code that calls these tables: only when the project has them.
    Tables(&'static [&'static str]),
}

struct File {
    /// Relative to the project root, with `/` separators.
    path: &'static str,
    content: &'static str,
    /// For a project with `output: "static"`: text put in after the line that starts with the
    /// first string, and the import names the second adds to the import from `spacepress/app`.
    static_output: Option<StaticOutput>,
    what: &'static str,
    when: When,
}

struct StaticOutput {
    after: &'static str,
    insert: &'static str,
    import: (&'static str, &'static str),
}

struct Module {
    name: &'static str,
    summary: &'static str,
    files: &'static [File],
    /// What to do next, one line each.
    next: &'static [&'static str],
}

const MODULES: &[Module] = &[
    Module {
        name: "db",
        summary: "SQL tables beside your WordPress content: table(), foreign keys, a schema check at deploy",
        files: &[
            File {
                path: "sp/tables.ts",
                content: include_str!("add/db/tables.ts"),
                static_output: None,
                what: "two example tables, with a reference between them and access rules",
                when: When::NoTables,
            },
            File {
                path: "tables.client.ts",
                content: include_str!("add/db/tables.client.ts"),
                static_output: None,
                what: "typed client calls for the example tables",
                when: When::Tables(&["projects", "tasks"]),
            },
            File {
                path: "sp/DATABASE.md",
                content: include_str!("add/db/DATABASE.md"),
                static_output: None,
                what: "how tables, migrations and the deploy check work",
                when: When::Always,
            },
        ],
        next: &[
            "spacepress build   writes the SQL for new tables to sp/_generated/migrations/ (commit it)",
            "spacepress dev     applies it to the local site",
            "tsc           checks the new files once tsconfig.json includes them (sp/DATABASE.md)",
        ],
    },
    Module {
        name: "api-reference",
        summary: "pages at /api that document the app's HTTP API from its OpenAPI document",
        files: &[
            File {
                path: "sp/app/api/page.tsx",
                content: include_str!("add/api-reference/page.tsx"),
                static_output: None,
                what: "/api: every operation, by tag",
                when: When::Always,
            },
            File {
                path: "sp/app/api/[tag]/[operation]/page.tsx",
                content: include_str!("add/api-reference/operation.tsx"),
                // A static site prebuilds every page, so the dynamic one lists its URLs. A site that
                // renders per request gets none: `staticParams` would make the page prebuilt, which
                // a guarded layout above /api refuses.
                static_output: Some(StaticOutput {
                    after: "import { ApiFields }",
                    insert: include_str!("add/api-reference/static-params.tsx"),
                    import: ("import type { PageProps }", "import type { PageProps, StaticParams }"),
                }),
                what: "/api/<tag>/<operation>: parameters, body, responses and a request to copy",
                when: When::Always,
            },
            File {
                path: "sp/components/ApiFields.tsx",
                content: include_str!("add/api-reference/ApiFields.tsx"),
                static_output: None,
                what: "the fields of a parameter list, body or response",
                when: When::Always,
            },
            File {
                path: "sp/API.md",
                content: include_str!("add/api-reference/API.md"),
                static_output: None,
                what: "what the pages read and how to change them",
                when: When::Always,
            },
        ],
        next: &[
            "spacepress dev     serves the reference at /api",
            "spacepress build   keeps it in step with the schema: it reads sp/_generated/openapi.json",
        ],
    },
    Module {
        name: "shop",
        summary: "WooCommerce products, native checkout, cart and editable storefront pages",
        files: &[
            File {
                path: "sp/shop.ts",
                content: include_str!("add/shop/shop.ts"),
                static_output: None,
                what: "store settings and commerce kinds",
                when: When::NoStore,
            },
            File {
                path: "sp/components/MiniCart.tsx",
                content: include_str!("add/shop/MiniCart.tsx"),
                static_output: None,
                what: "browser-only cart count",
                when: When::Shop,
            },
            File {
                path: "sp/shop.css",
                content: include_str!("add/shop/shop.css"),
                static_output: None,
                what: "storefront styles",
                when: When::Shop,
            },
            File {
                path: "public/shop.js",
                content: include_str!("add/shop/shop.js"),
                static_output: None,
                what: "cart, quantities and shipping client",
                when: When::Shop,
            },
            File {
                path: "sp/app/(shop)/shop/page.tsx",
                content: include_str!("add/shop/catalog.tsx"),
                static_output: None,
                what: "catalog page",
                when: When::Shop,
            },
            File {
                path: "sp/app/(shop)/shop/[...path]/page.tsx",
                content: include_str!("add/shop/category.tsx"),
                static_output: None,
                what: "category page",
                when: When::Shop,
            },
            File {
                path: "sp/app/(shop)/products/[slug]/page.tsx",
                content: include_str!("add/shop/product.tsx"),
                static_output: None,
                what: "product page",
                when: When::Shop,
            },
            File {
                path: "sp/app/(shop)/cart/page.tsx",
                content: include_str!("add/shop/cart.tsx"),
                static_output: None,
                what: "cart page",
                when: When::Shop,
            },
            File {
                path: "sp/app/(shop)/checkout/page.md",
                content: include_str!("add/shop/checkout.md"),
                static_output: None,
                what: "native Woo Checkout Block",
                when: When::Shop,
            },
            File {
                path: "content/products/ceramic-mug.md",
                content: include_str!("add/shop/mug.md"),
                static_output: None,
                what: "physical sample product",
                when: When::Shop,
            },
            File {
                path: "content/products/field-guide.md",
                content: include_str!("add/shop/guide.md"),
                static_output: None,
                what: "downloadable sample product",
                when: When::Shop,
            },
            File {
                path: "content/product-categories/home.md",
                content: include_str!("add/shop/category.md"),
                static_output: None,
                what: "sample category",
                when: When::Shop,
            },
            File {
                path: "content/product-tags/handmade.md",
                content: include_str!("add/shop/tag.md"),
                static_output: None,
                what: "sample tag",
                when: When::Shop,
            },
            File {
                path: "sp/app/(shop)/layout.tsx",
                content: include_str!("add/shop/layout.tsx"),
                static_output: None,
                what: "storefront layout",
                when: When::Shop,
            },
        ],
        next: &[
            "spacepress dev     starts the store with source products and native Woo checkout",
            "spacepress sync    merges catalog edits between source and WooCommerce",
        ],
    },
];

/// The modules, one line each, for usage errors and `spacepress help add`.
pub(crate) fn modules() -> String {
    let width = MODULES.iter().map(|m| m.name.len()).max().unwrap_or(0);
    let mut out = String::from("modules:\n");
    for module in MODULES {
        out.push_str(&format!("  {:width$}  {}\n", module.name, module.summary));
    }
    out
}

fn usage(message: &str) -> ExitCode {
    eprint!("{message}\nusage: spacepress add <module> [dir]\n{}", modules());
    ExitCode::from(2)
}

/// Whether anything at all is at `path`: a file, a directory, or a link that points nowhere.
fn occupied(path: &Path) -> bool {
    std::fs::symlink_metadata(path).is_ok()
}

fn table(schema: &Schema, key: Option<&str>) -> bool {
    schema
        .collections
        .values()
        .any(|c| !c.editor && !c.auth && c.taxonomy.is_none() && key.is_none_or(|key| c.key == key))
}

fn target_path(path: &str, layout: &crate::layout::Layout) -> String {
    if let Some(rest) = path.strip_prefix("sp/") {
        layout.rel(rest)
    } else {
        path.to_string()
    }
}

fn rewrite_dir(content: &str, layout: &crate::layout::Layout) -> String {
    if layout.is_default() {
        return content.to_string();
    }
    let dir = layout.root();
    content
        .replace("./sp/_generated/", &format!("./{dir}/_generated/"))
        .replace("sp/_generated/", &format!("{dir}/_generated/"))
        .replace("sp/app/", &format!("{dir}/app/"))
        .replace("sp/components/", &format!("{dir}/components/"))
        .replace("sp/tables.ts", &format!("{dir}/tables.ts"))
        .replace("sp/shop.ts", &format!("{dir}/shop.ts"))
        .replace("sp/DATABASE.md", &format!("{dir}/DATABASE.md"))
        .replace("sp/API.md", &format!("{dir}/API.md"))
        .replace("`sp/`", &format!("`{dir}/`"))
        .replace("\"sp/**/*.ts\"", &format!("\"{dir}/**/*.ts\""))
}

/// Why `file` doesn't belong in a project with this schema, or None when it does.
fn skip(file: &File, schema: &Schema, project: &Path, layout: &crate::layout::Layout) -> Option<String> {
    let shop_ts = layout.rel("shop.ts");
    match file.when {
        When::Always => None,
        When::NoStore => schema
            .store
            .is_some()
            .then(|| "the project already has a store".to_string()),
        When::Shop => (!(project.join(&shop_ts).is_file()
            && schema
                .collections
                .values()
                .any(|c| c.commerce.as_deref() == Some("product") && c.key == "products")))
        .then(|| format!("the project doesn't use the example {shop_ts} products kind")),
        When::NoTables => table(schema, None).then(|| "the project already has tables".to_string()),
        When::Tables(names) => names
            .iter()
            .find(|name| !table(schema, Some(name)))
            .map(|name| format!("it calls the example table `{name}`, which the project doesn't have")),
    }
}

fn with_static_output(content: &str, extra: &StaticOutput) -> String {
    let content = content.replacen(extra.import.0, extra.import.1, 1);
    let at = content
        .find(extra.after)
        .and_then(|i| content[i..].find('\n').map(|n| i + n + 1));
    match at {
        Some(at) => format!("{}{}{}", &content[..at], extra.insert, &content[at..]),
        None => content,
    }
}

/// What a run created, in order, so a failed run can take all of it out again.
#[derive(Default)]
struct Created {
    files: Vec<String>,
    directories: Vec<PathBuf>,
}

impl Created {
    /// Creates `path` with `content`, and the directories it needs. Fails when something is
    /// already there, so a file that appeared since [`occupied`] looked is never replaced. A file
    /// that could not be written whole is removed.
    fn file(&mut self, project: &Path, path: String, content: &str) -> std::io::Result<()> {
        let full = project.join(&path);
        let mut missing: Vec<PathBuf> = full
            .ancestors()
            .skip(1)
            .take_while(|directory| !occupied(directory))
            .map(Path::to_path_buf)
            .collect();
        missing.reverse();
        for directory in missing {
            std::fs::create_dir(&directory)?;
            self.directories.push(directory);
        }
        let mut file = std::fs::OpenOptions::new().write(true).create_new(true).open(&full)?;
        self.files.push(path);
        file.write_all(content.as_bytes())
    }

    fn undo(&self, project: &Path) {
        for file in &self.files {
            let _ = std::fs::remove_file(project.join(file));
        }
        // Only a directory this run made, and only while it is empty.
        for directory in self.directories.iter().rev() {
            let _ = std::fs::remove_dir(directory);
        }
    }
}

pub(crate) fn cli(args: &[String]) -> ExitCode {
    if let Some(option) = args.iter().find(|a| a.starts_with('-')) {
        return usage(&format!("unknown option {option} for spacepress add"));
    }
    let (name, dir) = match args {
        [name] => (name, "."),
        [name, dir] => (name, dir.as_str()),
        [] => return usage("spacepress add needs a module"),
        _ => return usage("spacepress add takes one module and one directory"),
    };
    let Some(module) = MODULES.iter().find(|m| m.name == name) else {
        return usage(&format!("no module {name}"));
    };
    let project = PathBuf::from(dir);
    let project = project.canonicalize().unwrap_or(project);
    let command = format!("spacepress add {}", module.name);
    if !project.join("spacepress.config.ts").is_file() {
        eprintln!(
            "{command}: no spacepress.config.ts in {}. Run it in a SpacePress project, or pass the project's directory.",
            project.display()
        );
        return ExitCode::from(1);
    }
    let refuse = |problem: &Diag, message: &str| {
        eprint!("{}", diag::render(problem, &project));
        eprintln!("{command}: {message}");
        ExitCode::from(1)
    };

    let layout = match crate::layout::Layout::from_project(&project) {
        Ok(layout) => layout,
        Err(problem) => return refuse(&problem, "invalid SpacePress config. Nothing was added."),
    };
    let shop_ts = layout.rel("shop.ts");
    let mut schema = match super::build::compile(&project) {
        Ok(schema) => schema,
        Err(problem) => return refuse(&problem, "the project has to compile first. Nothing was added."),
    };
    let mut created = Created::default();
    // Each file is judged against the project as it is with the files before it, so a file that
    // calls the example's tables sees them once they are written.
    let mut compiled = 0;
    let mut report = Vec::new();
    for file in module.files {
        let out_path = target_path(file.path, &layout);
        if occupied(&project.join(&out_path)) {
            report.push((out_path, "kept   ", "already there, not changed".to_string()));
            continue;
        }
        let shop_files = matches!(file.when, When::Shop) && created.files.iter().any(|f| f == &shop_ts);
        if !shop_files && !matches!(file.when, When::Always) && compiled < created.files.len() {
            schema = match super::build::compile(&project) {
                Ok(schema) => schema,
                Err(problem) => {
                    created.undo(&project);
                    return refuse(
                        &problem,
                        "its files don't compile next to your code, so nothing was added. If a name is taken, rename yours for a moment and run it again.",
                    );
                }
            };
            compiled = created.files.len();
        }
        if let Some(why) = if shop_files {
            None
        } else {
            skip(file, &schema, &project, &layout)
        } {
            report.push((out_path, "skipped", why));
            continue;
        }
        let content = match &file.static_output {
            Some(extra) if schema.output == crate::schema::Output::Static => with_static_output(file.content, extra),
            _ => file.content.to_string(),
        };
        let content = rewrite_dir(&content, &layout);
        if let Err(error) = created.file(&project, out_path.clone(), &content) {
            created.undo(&project);
            eprintln!("{command}: can't write {out_path}: {error}. Nothing was added.");
            return ExitCode::from(1);
        }
        report.push((out_path, "created", file.what.to_string()));
    }
    if compiled < created.files.len()
        && let Err(problem) = super::build::compile(&project)
    {
        created.undo(&project);
        return refuse(
            &problem,
            "its files don't compile next to your code, so nothing was added. If a name is taken, rename yours for a moment and run it again.",
        );
    }

    if created.files.is_empty() {
        println!("{command}: nothing to add");
    } else {
        println!("{command}: {}", module.summary);
    }
    let width = report.iter().map(|(path, ..)| path.len()).max().unwrap_or(0);
    for (path, status, detail) in &report {
        println!("  {status}  {path:width$}  {detail}");
    }
    if !created.files.is_empty() {
        println!("\nnext:");
        for line in module.next {
            println!("  {}", rewrite_dir(line, &layout));
        }
    }
    ExitCode::SUCCESS
}
