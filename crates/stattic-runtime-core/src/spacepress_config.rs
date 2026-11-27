use oxc_allocator::Allocator;
use oxc_ast::ast::{
    Argument, BindingPattern, Declaration, ExportDefaultDeclarationKind, Expression,
    ImportDeclarationSpecifier, ObjectPropertyKind, PropertyKey, Statement, VariableDeclaration,
};
use oxc_parser::Parser;
use oxc_span::{GetSpan, SourceType};
use std::collections::{BTreeMap, BTreeSet};

const SPACEFAST_CONFIG_MODULES: [&str; 5] = [
    "spacefast",
    "spacefast/config",
    "@spacefast/config",
    "@spacefast/cli",
    "@spacefast/common",
];

const TRANSPARENT_WRAPPER_NAMES: [&str; 2] = ["defineConfig", "defineSpaceConfig"];

/// Inspects `spacefast.config.ts` via Oxc and extracts a standalone SpacePress
/// config module if its default export carries a top-level `app` property.
/// Returns `None` for ordinary `spacefast.config.ts` files without `app`.
#[cfg(not(target_family = "wasm"))]
pub(crate) fn extract_inline_app_config(source: &str) -> Option<String> {
    extract_app(source, false)
}

/// MCP publishes resolved settings separately, so refuse outer settings here.
pub(crate) fn extract_inline_app_only_config(source: &str) -> Option<String> {
    extract_app(source, true)
}

struct WrapperImports<'a> {
    identifiers: BTreeSet<&'a str>,
    namespaces: BTreeSet<&'a str>,
}

fn is_spacefast_config_module(spec: &str) -> bool {
    SPACEFAST_CONFIG_MODULES.contains(&spec)
}

fn is_transparent_wrapper(
    callee: &Expression<'_>,
    wrappers: &WrapperImports<'_>,
    require_import: bool,
) -> bool {
    match unwrap_expr(callee) {
        Expression::Identifier(id) => {
            wrappers.identifiers.contains(id.name.as_str())
                || (!require_import && TRANSPARENT_WRAPPER_NAMES.contains(&id.name.as_str()))
        }
        Expression::StaticMemberExpression(member) => {
            if !TRANSPARENT_WRAPPER_NAMES.contains(&member.property.name.as_str()) {
                return false;
            }
            matches!(
                unwrap_expr(&member.object),
                Expression::Identifier(id) if wrappers.namespaces.contains(id.name.as_str())
            )
        }
        _ => false,
    }
}

fn append_variable_decl_without(
    out: &mut String,
    source: &str,
    decl: &VariableDeclaration<'_>,
    skip_ident: Option<&str>,
    exported: bool,
) {
    if let Some(target) = skip_ident {
        let declares_target = decl.declarations.iter().any(|d| {
            matches!(&d.id, BindingPattern::BindingIdentifier(id) if id.name.as_str() == target)
        });
        if declares_target {
            let kept: Vec<&str> = decl
                .declarations
                .iter()
                .filter(|d| {
                    !matches!(&d.id, BindingPattern::BindingIdentifier(id) if id.name.as_str() == target)
                })
                .filter_map(|d| source.get(d.span.start as usize..d.span.end as usize))
                .collect();
            if !kept.is_empty() {
                if exported {
                    out.push_str("export ");
                }
                out.push_str(decl.kind.as_str());
                out.push(' ');
                out.push_str(&kept.join(", "));
                out.push_str(";\n");
            }
            return;
        }
    }
    if exported {
        out.push_str("export ");
    }
    if let Some(slice) = source.get(decl.span.start as usize..decl.span.end as usize) {
        out.push_str(slice);
        out.push('\n');
    }
}

fn extract_app(source: &str, app_only: bool) -> Option<String> {
    let allocator = Allocator::default();
    let parsed = Parser::new(&allocator, source, SourceType::ts()).parse();
    if !parsed.diagnostics.is_empty() {
        return None;
    }

    // Collect top-level `const x = ...` bindings in case `export default x;` is used,
    // plus wrapper aliases imported from Spacefast config modules.
    let mut bindings: BTreeMap<&str, &Expression<'_>> = BTreeMap::new();
    let mut wrappers = WrapperImports {
        identifiers: BTreeSet::new(),
        namespaces: BTreeSet::new(),
    };
    let mut default_expr: Option<&Expression<'_>> = None;
    let mut default_stmt_span = None;

    for stmt in &parsed.program.body {
        match stmt {
            Statement::ImportDeclaration(import)
                if is_spacefast_config_module(import.source.value.as_str()) =>
            {
                if let Some(specifiers) = &import.specifiers {
                    for spec in specifiers {
                        match spec {
                            ImportDeclarationSpecifier::ImportSpecifier(named) => {
                                if TRANSPARENT_WRAPPER_NAMES
                                    .contains(&named.imported.name().as_str())
                                {
                                    wrappers.identifiers.insert(named.local.name.as_str());
                                }
                            }
                            ImportDeclarationSpecifier::ImportNamespaceSpecifier(ns) => {
                                wrappers.namespaces.insert(ns.local.name.as_str());
                            }
                            ImportDeclarationSpecifier::ImportDefaultSpecifier(_) => {}
                        }
                    }
                }
            }
            Statement::VariableDeclaration(decl) => {
                for declarator in &decl.declarations {
                    if let (BindingPattern::BindingIdentifier(ident), Some(init)) =
                        (&declarator.id, &declarator.init)
                    {
                        bindings.insert(ident.name.as_str(), init);
                    }
                }
            }
            Statement::ExportDeclaration(export) => {
                if let Declaration::VariableDeclaration(decl) = &export.declaration {
                    for declarator in &decl.declarations {
                        if let (BindingPattern::BindingIdentifier(ident), Some(init)) =
                            (&declarator.id, &declarator.init)
                        {
                            bindings.insert(ident.name.as_str(), init);
                        }
                    }
                }
            }
            Statement::ExportDefaultDeclaration(export) => {
                default_stmt_span = Some(export.span);
                if let Some(expr) = export_default_expression(&export.declaration) {
                    default_expr = Some(expr);
                }
            }
            _ => {}
        }
    }

    let app_expr = find_inline_app_expr(default_expr?, &bindings, &wrappers, app_only)?;
    let app_span = app_expr.span();
    let app_slice = source
        .get(app_span.start as usize..app_span.end as usize)?
        .trim();
    if app_slice.is_empty() {
        return None;
    }

    let skip_default_ident = match default_expr.map(unwrap_expr) {
        Some(Expression::Identifier(ident)) => Some(ident.name.as_str()),
        _ => None,
    };

    let target_module = "spacepress";
    let mut out = String::new();
    let needs_define_wrap = matches!(unwrap_expr(app_expr), Expression::ObjectExpression(_));
    if needs_define_wrap {
        out.push_str(&format!(
            "import {{ defineConfig as __sp_defineConfig }} from \"{target_module}\";\n"
        ));
    }

    for stmt in &parsed.program.body {
        if default_stmt_span.is_some_and(|span| span == stmt.span()) {
            continue;
        }
        match stmt {
            Statement::ImportDeclaration(import) => {
                if is_spacefast_config_module(import.source.value.as_str()) {
                    continue;
                }
                let span = import.span;
                if let Some(slice) = source.get(span.start as usize..span.end as usize) {
                    out.push_str(slice);
                    out.push('\n');
                }
            }
            Statement::VariableDeclaration(decl) => {
                append_variable_decl_without(&mut out, source, decl, skip_default_ident, false);
            }
            Statement::ExportDeclaration(export) => {
                if let Declaration::VariableDeclaration(decl) = &export.declaration {
                    append_variable_decl_without(&mut out, source, decl, skip_default_ident, true);
                } else {
                    let span = export.span;
                    if let Some(slice) = source.get(span.start as usize..span.end as usize) {
                        out.push_str(slice);
                        out.push('\n');
                    }
                }
            }
            Statement::ExportNamedDeclaration(export) => {
                let span = export.span;
                if let Some(slice) = source.get(span.start as usize..span.end as usize) {
                    out.push_str(slice);
                    out.push('\n');
                }
            }
            Statement::FunctionDeclaration(_)
            | Statement::ClassDeclaration(_)
            | Statement::TSTypeAliasDeclaration(_)
            | Statement::TSInterfaceDeclaration(_)
            | Statement::TSEnumDeclaration(_) => {
                let span = stmt.span();
                if let Some(slice) = source.get(span.start as usize..span.end as usize) {
                    out.push_str(slice);
                    out.push('\n');
                }
            }
            _ => {}
        }
    }

    if needs_define_wrap {
        out.push_str(&format!("export default __sp_defineConfig({app_slice});\n"));
    } else {
        out.push_str(&format!("export default {app_slice};\n"));
    }
    Some(out)
}

fn export_default_expression<'a>(
    decl: &'a ExportDefaultDeclarationKind<'a>,
) -> Option<&'a Expression<'a>> {
    decl.as_expression()
}

fn unwrap_expr<'a>(mut expr: &'a Expression<'a>) -> &'a Expression<'a> {
    loop {
        match expr {
            Expression::ParenthesizedExpression(inner) => expr = &inner.expression,
            Expression::TSAsExpression(inner) => expr = &inner.expression,
            Expression::TSSatisfiesExpression(inner) => expr = &inner.expression,
            Expression::TSNonNullExpression(inner) => expr = &inner.expression,
            Expression::TSTypeAssertion(inner) => expr = &inner.expression,
            _ => return expr,
        }
    }
}

fn find_inline_app_expr<'a>(
    expr: &'a Expression<'a>,
    bindings: &BTreeMap<&str, &'a Expression<'a>>,
    wrappers: &WrapperImports<'a>,
    app_only: bool,
) -> Option<&'a Expression<'a>> {
    let mut visited = std::collections::HashSet::new();
    find_inline_app_expr_inner(expr, bindings, wrappers, &mut visited, 0, app_only)
}

fn find_inline_app_expr_inner<'a>(
    expr: &'a Expression<'a>,
    bindings: &BTreeMap<&str, &'a Expression<'a>>,
    wrappers: &WrapperImports<'a>,
    visited: &mut std::collections::HashSet<&'a str>,
    depth: usize,
    app_only: bool,
) -> Option<&'a Expression<'a>> {
    if depth > 64 {
        return None;
    }
    match unwrap_expr(expr) {
        Expression::ObjectExpression(obj) if !app_only || obj.properties.len() == 1 => {
            find_app_property(obj)
        }
        Expression::CallExpression(call)
            if !app_only
                || (call.arguments.len() == 1
                    && is_transparent_wrapper(&call.callee, wrappers, app_only)) =>
        {
            let first = call.arguments.first()?;
            match first {
                Argument::SpreadElement(_) => None,
                arg => arg.as_expression().and_then(|inner| {
                    find_inline_app_expr_inner(
                        inner,
                        bindings,
                        wrappers,
                        visited,
                        depth + 1,
                        app_only,
                    )
                }),
            }
        }
        Expression::Identifier(ident) => {
            let name = ident.name.as_str();
            if !visited.insert(name) {
                return None;
            }
            let result = bindings.get(name).and_then(|bound| {
                find_inline_app_expr_inner(bound, bindings, wrappers, visited, depth + 1, app_only)
            });
            visited.remove(name);
            result
        }
        _ => None,
    }
}

fn find_app_property<'a>(
    obj: &'a oxc_ast::ast::ObjectExpression<'a>,
) -> Option<&'a Expression<'a>> {
    for prop in &obj.properties {
        let ObjectPropertyKind::ObjectProperty(prop) = prop else {
            continue;
        };
        let is_app = match &prop.key {
            PropertyKey::StaticIdentifier(id) => id.name == "app",
            PropertyKey::StringLiteral(lit) => lit.value == "app",
            _ => false,
        };
        if is_app {
            return Some(&prop.value);
        }
    }
    None
}

#[cfg(test)]
mod tests {
    use super::{extract_inline_app_config, extract_inline_app_only_config};

    #[test]
    fn preserves_sibling_declarators_when_stripping_outer_config() {
        let source = r#"
            import { app as importedApp } from "./app";
            const app = importedApp, config = { app };
            export default config;
        "#;
        let extracted = extract_inline_app_only_config(source).expect("extracted");
        assert!(extracted.contains("const app = importedApp;"));
        assert!(!extracted.contains("config ="));
        assert!(extracted.contains("export default app;"));
    }

    #[test]
    fn preserves_function_declarations_referenced_by_app() {
        let source = r#"
            import { app as importedApp } from "./app";
            function makeApp() { return importedApp; }
            export default { app: makeApp() };
        "#;
        let extracted = extract_inline_app_only_config(source).expect("extracted");
        assert!(extracted.contains("function makeApp() { return importedApp; }"));
        assert!(extracted.contains("export default makeApp();"));
    }

    #[test]
    fn rejects_non_transparent_calls_like_object_assign() {
        let assign = r#"
            import { app } from "./app";
            const runtimeConfig = { kind: "functions" };
            export default Object.assign({ app }, { runtime: runtimeConfig });
        "#;
        assert!(extract_inline_app_only_config(assign).is_none());
        // The engine path (app_only = false) retains prior behavior so existing
        // wrapped spacefast.config.ts files stay recognized as SpacePress.
        assert!(extract_inline_app_config(assign).is_some());

        let aliased = r#"
            import { defineConfig as wrap } from "spacefast";
            export default wrap({ app: { name: "blog" } });
        "#;
        let extracted = extract_inline_app_only_config(aliased).expect("aliased defineConfig");
        assert!(extracted.contains("name: \"blog\""));
        assert!(!extracted.contains("wrap"));

        let local_shadow = r#"
            function defineConfig(c: { app: { name: string } }) {
                return { ...c, runtime: { kind: "functions" } };
            }
            export default defineConfig({ app: { name: "blog" } });
        "#;
        assert!(extract_inline_app_only_config(local_shadow).is_none());
        assert!(extract_inline_app_config(local_shadow).is_some());
    }
}
