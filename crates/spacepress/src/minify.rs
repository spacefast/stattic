//! Minifies the browser modules the runtime and app plugins ship. The typed client, user-authored
//! stores and runtime source stay readable. Only the files placed in plugin artifacts are minified.

/// `source`, an ES module, minified: whitespace and comments removed, local names shortened,
/// expressions compressed. Exports, imports and top-level names a module exposes keep their names;
/// function and class names are kept too, since the client reads `error.name`-style values. A source
/// that doesn't parse is returned unchanged (the build would have refused it already).
pub fn module(source: &str) -> String {
    use oxc_allocator::Allocator;
    use oxc_codegen::{Codegen, CodegenOptions};
    use oxc_minifier::{CompressOptions, MangleOptions, Minifier, MinifierOptions};
    use oxc_parser::Parser;
    use oxc_span::SourceType;
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, source, SourceType::mjs()).parse();
    if !parsed.diagnostics.is_empty() {
        return source.to_string();
    }
    let mut program = parsed.program;
    let options = MinifierOptions {
        mangle: Some(MangleOptions {
            keep_names: oxc_minifier::MangleOptionsKeepNames::all_true(),
            ..MangleOptions::default()
        }),
        compress: Some(CompressOptions::safest()),
        ..MinifierOptions::default()
    };
    let minified = Minifier::new(options).minify(&allocator, &mut program);
    Codegen::new()
        .with_options(CodegenOptions::minify())
        .with_scoping(minified.scoping)
        .with_private_member_mappings(minified.class_private_mappings)
        .build(&program)
        .code
}

#[cfg(test)]
mod tests {
    #[test]
    fn a_module_keeps_its_exports_and_names_and_loses_its_spaces() {
        let source = "// a comment\nimport { a } from \"x\";\nexport class SpacePressError extends Error {\n  constructor(message) {\n    super(message);\n    this.name = \"SpacePressError\";\n  }\n}\nexport function call(longName) {\n  const another = longName + a;\n  return another;\n}\n";
        let out = super::module(source);
        assert!(out.len() < source.len(), "{out}");
        assert!(!out.contains("a comment") && !out.contains('\n'), "{out}");
        for kept in [
            "class SpacePressError",
            "export",
            "function call",
            "from\"x\"",
            "this.name=",
        ] {
            assert!(out.contains(kept), "{kept} in {out}");
        }
        assert!(!out.contains("longName") && !out.contains("another"), "{out}");
        assert_eq!(super::module("this is not ( javascript"), "this is not ( javascript");
    }
}
