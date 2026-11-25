//! Collect render dependencies from the emitted function graph, after app exports have names.

use crate::compile::RouteEffects;
use crate::schema::{Access, AppRoute, CacheClass, RouteDependency, RoutePlan, Schema};
use std::collections::{BTreeSet, HashMap, HashSet};

pub(super) fn build(schema: &mut Schema, effects: &RouteEffects, calls: &HashMap<String, HashSet<String>>) {
    let Some(app) = &mut schema.app else { return };
    let auto_prebuild = schema.store.is_none() && schema.wp_hooks.is_empty() && schema.locales.is_none();
    for (route, special) in app
        .routes
        .iter_mut()
        .map(|route| (route, None))
        .chain(app.not_found.iter_mut().map(|route| (route, Some("not-found"))))
        .chain(app.login.iter_mut().map(|route| (route, Some("login"))))
    {
        let files = route
            .layouts
            .iter()
            .chain(route.page.iter())
            .filter_map(|f| app.files.get(f));
        let mut pending = Vec::new();
        let mut private = special == Some("login");
        let mut blank = false;
        let mut pure = route.page.is_some();
        for file in files {
            if let Some(guard) = &file.guard {
                private = true;
                if let crate::schema::Access::Fn(name) = guard {
                    pending.push(name.as_str());
                }
            }
            if let Some(name) = file.component.as_deref() {
                pending.push(name);
                pure &= effects.literal_markup.contains(name);
            }
            if let Some(name) = file.metadata.as_ref().and_then(|meta| meta["fn"].as_str()) {
                pure = false;
                pending.push(name);
            }
            if let Some(template) = &file.template {
                blank = template == "blank";
            }
        }
        let page = route.page.as_ref().and_then(|page| app.files.get(page));
        let search = page.and_then(|page| page.search.as_ref());
        let mut seen = HashSet::new();
        let mut seen_data = HashSet::new();
        let mut dependencies = BTreeSet::new();
        let mut data = Vec::new();
        if let Some((collection, bindings)) = &route.kind {
            data.push(RouteDependency::RouteItem {
                collection: collection.clone(),
                bindings: bindings.iter().cloned().collect(),
            });
            if schema.collections.get(collection).is_some_and(|spec| spec.hierarchical) {
                data.push(RouteDependency::Relation {
                    collection: collection.clone(),
                    field: "parent".into(),
                    target: collection.clone(),
                });
            }
        }
        loop {
            if let Some(name) = pending.pop() {
                if seen.insert(name) {
                    data.extend(effects.dependencies.get(name).into_iter().flatten().cloned());
                    pending.extend(calls.get(name).into_iter().flatten().map(String::as_str));
                    private |= effects.private.contains(name);
                }
                continue;
            }
            let Some(dep) = data.pop() else { break };
            let key = match &dep {
                RouteDependency::List { collection }
                | RouteDependency::Count { collection }
                | RouteDependency::Search { collection }
                | RouteDependency::Row { collection }
                | RouteDependency::RouteItem { collection, .. } => Some((false, collection.as_str())),
                RouteDependency::Relation { target, .. } => Some((false, target.as_str())),
                RouteDependency::Global { name, .. } => Some((true, name.as_str())),
                _ => None,
            };
            if let Some((global, key)) = key
                && seen_data.insert((global, key.to_string()))
            {
                let rules = if global {
                    schema.globals.get(key).map(|spec| (&spec.access, &spec.fields))
                } else {
                    schema.collections.get(key).map(|spec| (&spec.access, &spec.columns))
                };
                if let Some((rules, fields)) = rules {
                    for access in std::iter::once(rules).chain(fields.values().map(|field| &field.access)) {
                        if let Some(Access::Fn(name)) = access.get("read") {
                            pending.push(name);
                        }
                    }
                }
            }
            dependencies.insert(dep);
        }
        if route.page.is_none() || special.is_some() {
            dependencies.insert(RouteDependency::opaque(special.unwrap_or("HTTP handler")));
        }
        let immutable = pure && blank && search.is_none() && dependencies.is_empty() && literal(route);
        let class = if private {
            CacheClass::RequestPrivate
        } else if immutable {
            CacheClass::Immutable
        } else if dependencies.is_empty()
            || dependencies
                .iter()
                .any(|dep| matches!(dep, RouteDependency::Opaque { .. }))
        {
            CacheClass::Unknown
        } else {
            CacheClass::AnonymousPublic
        };
        // Keep the default shared window for data and unknown routes while runtime WordPress hooks
        // can still contribute to the response. Literal markup can skip component rendering on a
        // canonical redirect even when the active theme prevents an early file hit.
        let window = if private { 0 } else { 300 };
        let dependencies = dependencies.into_iter().collect::<Vec<_>>();
        let fixed_purge = literal(route)
            && special.is_none()
            && matches!(class, CacheClass::Immutable | CacheClass::AnonymousPublic)
            && dependencies.iter().all(|dep| dep.purge_key().is_some());
        if immutable && auto_prebuild && page.is_some_and(|page| page.prerender.is_none() && page.methods.is_empty()) {
            route.prerender = true;
        }
        route.plan = Some(RoutePlan {
            class,
            window,
            dependencies,
            search: search
                .into_iter()
                .flat_map(|search| search.fields.keys().cloned())
                .collect(),
            fixed_purge,
        });
    }
}

fn literal(route: &AppRoute) -> bool {
    !route.pattern.split('/').any(|segment| segment.starts_with([':', '*']))
}
