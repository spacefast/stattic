//! `adminWidget({ ... })` and `adminDashboard({ ... })`: composable SpacePress Admin widgets
//! and custom dashboards declared in TypeScript.
//!
//! Both register by export name in kebab case, mirroring `adminScreen()`. Widgets read data
//! through readonly `action()` Abilities and compile to declarations stored in `schema.php`
//! (`widgets` and `dashboards`), registered on `sp_admin_dashboards` when SpacePress Admin loads.

use crate::diag::{Diag, Result, Span, suggest};
use crate::eval::{Evaluator, to_json};
use crate::extract::Extracted;
use crate::schema::Action;
use crate::value::{ActionVal, Value};
use crate::wordpress::abilities::kebab;
use indexmap::IndexMap;
use serde_json::{Map, Value as Json, json};
use std::rc::Rc;

const WIDGET_KEYS: &[&str] = &[
    "title",
    "description",
    "info",
    "access",
    "capability",
    "action",
    "input",
    "display",
    "items",
    "fields",
    "metrics",
    "width",
    "height",
    "link",
];
const DISPLAY_KINDS: &[&str] = &["metrics", "list", "table", "summary"];
const FIELD_KEYS: &[&str] = &["label", "type", "description"];
const FIELD_TYPES: &[&str] = &["text", "integer", "number", "boolean", "datetime"];
const METRIC_KEYS: &[&str] = &["id", "label", "path", "format"];
const METRIC_FORMATS: &[&str] = &["number", "currency", "percent", "text"];
const LINK_KEYS: &[&str] = &["label", "to"];

const DASHBOARD_KEYS: &[&str] = &[
    "title",
    "description",
    "access",
    "capability",
    "home",
    "menu",
    "widgets",
];
const DASHBOARD_WIDGET_ITEM_KEYS: &[&str] = &["widget", "width", "height", "fullWidth"];

/// Built-in SpacePress / SpacePress dashboard widget types an `adminDashboard()` may reference by id.
pub const BUILTIN_WIDGETS: &[&str] = &[
    "sp/content-overview",
    "sp/recent-activity",
    "sp/drafts-queue",
    "sp/app-status",
    "sp/store-pulse",
];

const ROLES: &[(&str, &str)] = &[
    ("admin", "manage_options"),
    ("editor", "edit_others_posts"),
    ("author", "edit_posts"),
    ("subscriber", "read"),
    ("authenticated", "read"),
];

pub fn widgets(v: &Value, span: Span, ev: &Evaluator<'_>, out: &mut Extracted) -> Result<()> {
    let Value::Object(entries) = v else {
        return Err(Diag::new(
            span,
            "`widgets` must be an object of adminWidget(...) values",
        ));
    };
    let app = kebab(&out.schema.name);
    for (name, value) in entries.borrow().iter() {
        let nspan = ev.key_span(v, name).unwrap_or(span);
        let Value::Widget(widget) = value else {
            return Err(Diag::new(
                nspan,
                format!("`{name}` must be wrapped in adminWidget({{ ... }})"),
            ));
        };
        let id = kebab(name);
        if out.schema.widgets.contains_key(&id) {
            return Err(
                Diag::new(widget.span, format!("two widgets are both registered as `{id}`"))
                    .help("a widget's id is its export name in kebab case; rename one export"),
            );
        }
        let declaration = WidgetExtractor { ev, out, app: &app }.declaration(&id, widget)?;
        out.widget_values.insert(Rc::as_ptr(widget) as usize, id.clone());
        out.schema.widgets.insert(id, declaration);
    }
    Ok(())
}

pub fn dashboards(v: &Value, span: Span, ev: &Evaluator<'_>, out: &mut Extracted) -> Result<()> {
    let Value::Object(entries) = v else {
        return Err(Diag::new(
            span,
            "`dashboards` must be an object of adminDashboard(...) values",
        ));
    };
    let mut home_owner: Option<String> = None;
    for (name, value) in entries.borrow().iter() {
        let nspan = ev.key_span(v, name).unwrap_or(span);
        let Value::Dashboard(dashboard) = value else {
            return Err(Diag::new(
                nspan,
                format!("`{name}` must be wrapped in adminDashboard({{ ... }})"),
            ));
        };
        let id = kebab(name);
        if out.schema.dashboards.contains_key(&id) {
            return Err(
                Diag::new(dashboard.span, format!("two dashboards are both registered as `{id}`"))
                    .help("a dashboard's id is its export name in kebab case; rename one export"),
            );
        }
        let declaration = DashboardExtractor { ev, out }.declaration(dashboard)?;
        if declaration.get("home").and_then(Json::as_bool) == Some(true) {
            if let Some(prev) = &home_owner {
                return Err(
                    Diag::new(dashboard.span, format!("both `{prev}` and `{id}` set `home: true`"))
                        .help("at most one adminDashboard() may customize the default Home dashboard"),
                );
            }
            home_owner = Some(id.clone());
        }
        out.schema.dashboards.insert(id, declaration);
    }
    Ok(())
}

fn options(v: &Value, span: Span, what: &str, allowed: &[&str], ev: &Evaluator<'_>) -> Result<IndexMap<String, Value>> {
    let Value::Object(o) = v else {
        return Err(Diag::new(span, format!("{what} must be an object")));
    };
    let o = o.borrow().clone();
    for k in o.keys() {
        if !allowed.contains(&k.as_str()) {
            let mut d = Diag::new(ev.key_span(v, k).unwrap_or(span), format!("{what} has no option `{k}`"));
            d = match suggest(k, allowed.iter().copied()) {
                Some(s) => d.help(format!("did you mean `{s}`?")),
                None => d.help(format!("use {}", allowed.join(", "))),
            };
            return Err(d);
        }
    }
    Ok(o.into_iter().filter(|(_, v)| !matches!(v, Value::Undef)).collect())
}

fn text(o: &IndexMap<String, Value>, key: &str, span: Span, what: &str) -> Result<Option<String>> {
    match o.get(key) {
        None => Ok(None),
        Some(Value::Str(s)) if !s.is_empty() => Ok(Some(s.to_string())),
        Some(other) => Err(Diag::new(
            span,
            format!("{what} `{key}` must be a nonempty string, not {}", other.describe()),
        )
        .help(format!("write `{key}: \"...\"`"))),
    }
}

fn span_int(v: &Value, span: Span, key: &str) -> Result<u64> {
    match v {
        Value::Num(n) if n.is_finite() && n.fract() == 0.0 && *n >= 1.0 && *n <= 4.0 => Ok(*n as u64),
        other => Err(Diag::new(
            span,
            format!("`{key}` must be an integer from 1 to 4, not {}", other.describe()),
        )
        .help(format!("write `{key}: 2`"))),
    }
}

fn capability(ev: &Evaluator<'_>, cfg: &Value, o: &IndexMap<String, Value>, span: Span, what: &str) -> Result<String> {
    let at = |key: &str| ev.key_span(cfg, key).unwrap_or(span);
    match (o.get("access"), o.get("capability")) {
        (Some(_), Some(_)) => Err(Diag::new(
            at("capability"),
            format!("{what} has `access` or `capability`, not both"),
        )
        .help("keep `access: \"editor\"` (a SpacePress role) or `capability: \"edit_others_posts\"`")),
        (Some(Value::Str(role)), None) => ROLES
            .iter()
            .find(|(r, _)| *r == role.as_ref())
            .map(|(_, cap)| cap.to_string())
            .ok_or_else(|| {
                let mut d = Diag::new(at("access"), format!("`access: \"{role}\"` isn't a role {what} takes"));
                d = match suggest(role, ROLES.iter().map(|(r, _)| *r)) {
                    Some(s) => d.help(format!("did you mean `\"{s}\"`?")),
                    None => d.help("use \"admin\", \"editor\", \"author\", \"subscriber\" or \"authenticated\""),
                };
                d
            }),
        (None, Some(Value::Str(cap))) if !cap.is_empty() => Ok(cap.to_string()),
        (Some(other), None) | (None, Some(other)) => Err(Diag::new(
            at(if o.contains_key("access") {
                "access"
            } else {
                "capability"
            }),
            format!("{what}'s access is a string, not {}", other.describe()),
        )
        .help("write `access: \"editor\"` or `capability: \"edit_others_posts\"`")),
        (None, None) => Ok("manage_options".into()),
    }
}

struct WidgetExtractor<'a, 'b, 'c> {
    ev: &'a Evaluator<'b>,
    out: &'a Extracted,
    app: &'c str,
}

impl WidgetExtractor<'_, '_, '_> {
    fn at(&self, obj: &Value, key: &str, fallback: Span) -> Span {
        self.ev.key_span(obj, key).unwrap_or(fallback)
    }

    fn action_ref(&self, v: Option<&Value>, span: Span) -> Result<(&Action, String)> {
        let name = match v {
            Some(Value::Action(a)) => self.out.action_values.get(&(Rc::as_ptr(a) as usize)),
            _ => None,
        };
        let Some(name) = name else {
            let got = v.map_or_else(|| "missing".to_string(), |v| v.describe());
            return Err(
                Diag::new(span, format!("adminWidget() `action` names an action, but it is {got}"))
                    .help("pass an exported readonly action: `action: salesSummary`"),
            );
        };
        let action = &self.out.schema.actions[name];
        if !action.agents {
            return Err(Diag::new(
                span,
                format!("the action `{name}` is `agents: false`, so it has no Ability a widget can run"),
            )
            .help(format!("remove `agents: false` from `{name}`")));
        }
        if action.signature.is_some() {
            return Err(Diag::new(
                span,
                format!("the action `{name}` takes a signed request, which a widget can't send"),
            )
            .help("use an action with `access` instead of `signature`"));
        }
        if !action.readonly {
            return Err(Diag::new(
                span,
                format!("adminWidget() `action` runs `{name}`, which isn't readonly"),
            )
            .help(format!("add `readonly: true` to `{name}`")));
        }
        Ok((action, format!("{}/{}", self.app, kebab(name))))
    }

    fn declaration(&self, id: &str, widget: &Rc<ActionVal>) -> Result<Json> {
        let cfg = &widget.config;
        let span = widget.span;
        let o = options(cfg, span, "adminWidget()", WIDGET_KEYS, self.ev)?;
        let mut decl = Map::new();
        decl.insert("id".into(), json!(id));
        decl.insert("type".into(), json!(format!("sp-widget/{id}")));
        let Some(title) = text(&o, "title", self.at(cfg, "title", span), "the widget's")? else {
            return Err(Diag::new(span, "adminWidget() needs a `title`").help("add `title: \"Sales this week\"`"));
        };
        decl.insert("title".into(), json!(title));
        if let Some(d) = text(&o, "description", self.at(cfg, "description", span), "the widget's")? {
            decl.insert("description".into(), json!(d));
        }
        if let Some(info) = text(&o, "info", self.at(cfg, "info", span), "the widget's")? {
            decl.insert("info".into(), json!(info));
        }
        decl.insert(
            "capability".into(),
            json!(capability(self.ev, cfg, &o, span, "a widget")?),
        );

        let (action, ability) = self.action_ref(o.get("action"), self.at(cfg, "action", span))?;
        decl.insert("ability".into(), json!(ability));

        if let Some(iv) = o.get("input") {
            let ispan = self.at(cfg, "input", span);
            let Value::Object(_) = iv else {
                return Err(Diag::new(ispan, format!("`input` is an object, not {}", iv.describe())));
            };
            let json_in = to_json(iv).ok_or_else(|| Diag::new(ispan, "`input` must be plain data"))?;
            for key in json_in.as_object().into_iter().flatten().map(|(k, _)| k) {
                if !action.input.contains_key(key) {
                    return Err(Diag::new(
                        self.at(iv, key, ispan),
                        format!("the action `{}` has no input `{key}`", action.name),
                    ));
                }
            }
            decl.insert("input".into(), json_in);
        }
        if let Some((field, _)) = action.input.iter().find(|(k, c)| {
            !c.optional_on_insert()
                && !decl
                    .get("input")
                    .and_then(Json::as_object)
                    .is_some_and(|m| m.contains_key(*k))
        }) {
            return Err(Diag::new(
                self.at(cfg, "input", span),
                format!("the action `{}` needs the input `{field}`", action.name),
            )
            .help(format!("add `input: {{ {field}: ... }}`")));
        }

        if let Some(items) = text(&o, "items", self.at(cfg, "items", span), "the widget's")? {
            decl.insert("items".into(), json!(items));
        }

        if let Some(fv) = o.get("fields") {
            let fspan = self.at(cfg, "fields", span);
            let Value::Object(fo) = fv else {
                return Err(Diag::new(fspan, "`fields` must be an object keyed by field id"));
            };
            let mut fields = Vec::new();
            for (fid, f) in fo.borrow().iter() {
                let idspan = self.at(fv, fid, fspan);
                let map = options(f, idspan, &format!("field `{fid}`"), FIELD_KEYS, self.ev)?;
                let mut field = Map::new();
                field.insert("id".into(), json!(fid));
                if let Some(l) = text(&map, "label", self.at(f, "label", idspan), &format!("field `{fid}`"))? {
                    field.insert("label".into(), json!(l));
                }
                if let Some(ty) = map.get("type") {
                    match ty {
                        Value::Str(t) if FIELD_TYPES.contains(&t.as_ref()) => {
                            field.insert("type".into(), json!(t.as_ref()));
                        }
                        _ => {
                            return Err(Diag::new(
                                self.at(f, "type", idspan),
                                format!("field `{fid}` has an unsupported widget field type"),
                            )
                            .help(format!("use one of {}", FIELD_TYPES.join(", "))));
                        }
                    }
                }
                if let Some(d) = text(
                    &map,
                    "description",
                    self.at(f, "description", idspan),
                    &format!("field `{fid}`"),
                )? {
                    field.insert("description".into(), json!(d));
                }
                fields.push(Json::Object(field));
            }
            decl.insert("fields".into(), Json::Array(fields));
        }

        if let Some(mv) = o.get("metrics") {
            let mspan = self.at(cfg, "metrics", span);
            let Value::Array(items) = mv else {
                return Err(Diag::new(
                    mspan,
                    "`metrics` must be an array of `{ label, path, format? }`",
                ));
            };
            let mut metrics = Vec::new();
            for (idx, item) in items.borrow().iter().enumerate() {
                let mo = options(item, mspan, "a widget metric", METRIC_KEYS, self.ev)?;
                let mut m = Map::new();
                let Some(label) = text(&mo, "label", self.at(item, "label", mspan), "a metric's")? else {
                    return Err(Diag::new(mspan, "each widget metric needs a `label`"));
                };
                let Some(path) = text(&mo, "path", self.at(item, "path", mspan), "a metric's")? else {
                    return Err(Diag::new(mspan, format!("metric `{label}` needs a `path`")));
                };
                let mid =
                    text(&mo, "id", self.at(item, "id", mspan), "a metric's")?.unwrap_or_else(|| format!("m{idx}"));
                m.insert("id".into(), json!(mid));
                m.insert("label".into(), json!(label));
                m.insert("path".into(), json!(path));
                if let Some(fmt) = mo.get("format") {
                    match fmt {
                        Value::Str(s) if METRIC_FORMATS.contains(&s.as_ref()) => {
                            m.insert("format".into(), json!(s.as_ref()));
                        }
                        _ => {
                            return Err(Diag::new(
                                self.at(item, "format", mspan),
                                "metric `format` must be number, currency, percent or text",
                            ));
                        }
                    }
                }
                metrics.push(Json::Object(m));
            }
            decl.insert("metrics".into(), Json::Array(metrics));
        }

        let display = match o.get("display") {
            Some(Value::Str(d)) if DISPLAY_KINDS.contains(&d.as_ref()) => d.to_string(),
            Some(other) => {
                let mut d = Diag::new(
                    self.at(cfg, "display", span),
                    format!("`display` is {}, which isn't a widget display mode", other.describe()),
                );
                d = d.help(format!("use one of {}", DISPLAY_KINDS.join(", ")));
                return Err(d);
            }
            None => {
                if decl.contains_key("metrics") {
                    "metrics".to_string()
                } else if decl.contains_key("items") || decl.contains_key("fields") {
                    "list".to_string()
                } else {
                    "summary".to_string()
                }
            }
        };
        decl.insert("display".into(), json!(display));

        if let Some(w) = o.get("width") {
            decl.insert(
                "width".into(),
                json!(span_int(w, self.at(cfg, "width", span), "width")?),
            );
        }
        if let Some(h) = o.get("height") {
            decl.insert(
                "height".into(),
                json!(span_int(h, self.at(cfg, "height", span), "height")?),
            );
        }
        if let Some(lv) = o.get("link") {
            let lspan = self.at(cfg, "link", span);
            let lo = options(lv, lspan, "`link`", LINK_KEYS, self.ev)?;
            let Some(label) = text(&lo, "label", self.at(lv, "label", lspan), "`link`")? else {
                return Err(Diag::new(lspan, "`link` needs a `label`"));
            };
            let Some(to) = text(&lo, "to", self.at(lv, "to", lspan), "`link`")? else {
                return Err(Diag::new(lspan, "`link` needs a `to` route path"));
            };
            decl.insert("link".into(), json!({ "label": label, "to": to }));
        }

        Ok(Json::Object(decl))
    }
}

struct DashboardExtractor<'a, 'b> {
    ev: &'a Evaluator<'b>,
    out: &'a Extracted,
}

impl DashboardExtractor<'_, '_> {
    fn at(&self, obj: &Value, key: &str, fallback: Span) -> Span {
        self.ev.key_span(obj, key).unwrap_or(fallback)
    }

    fn resolve_widget_type(&self, v: &Value, span: Span) -> Result<String> {
        match v {
            Value::Widget(w) => self
                .out
                .widget_values
                .get(&(Rc::as_ptr(w) as usize))
                .map(|id| format!("sp-widget/{id}"))
                .ok_or_else(|| {
                    Diag::new(span, "dashboard widget isn't an exported adminWidget()")
                        .help("export the widget: `export const salesWidget = adminWidget({ ... })`")
                }),
            Value::Str(s) if BUILTIN_WIDGETS.contains(&s.as_ref()) => Ok(s.to_string()),
            Value::Str(s) => {
                if let Some(legacy) = s.strip_prefix("tp/")
                    && let Some(current) = BUILTIN_WIDGETS.iter().find(|id| id.strip_prefix("sp/") == Some(legacy))
                {
                    return Ok((*current).to_string());
                }
                let mut d = Diag::new(span, format!("unknown built-in dashboard widget `{s}`"));
                d = match suggest(s, BUILTIN_WIDGETS.iter().copied()) {
                    Some(hit) => d.help(format!("did you mean `\"{hit}\"`?")),
                    None => d.help(format!(
                        "pass an exported adminWidget() or one of {}",
                        BUILTIN_WIDGETS.join(", ")
                    )),
                };
                Err(d)
            }
            other => Err(Diag::new(
                span,
                format!(
                    "dashboard `widgets` entry must be a widget or widget id, not {}",
                    other.describe()
                ),
            )),
        }
    }

    fn declaration(&self, dashboard: &Rc<ActionVal>) -> Result<Json> {
        let cfg = &dashboard.config;
        let span = dashboard.span;
        let o = options(cfg, span, "adminDashboard()", DASHBOARD_KEYS, self.ev)?;
        let mut decl = Map::new();
        let Some(title) = text(&o, "title", self.at(cfg, "title", span), "the dashboard's")? else {
            return Err(Diag::new(span, "adminDashboard() needs a `title`").help("add `title: \"Operations\"`"));
        };
        decl.insert("title".into(), json!(title));
        if let Some(d) = text(&o, "description", self.at(cfg, "description", span), "the dashboard's")? {
            decl.insert("description".into(), json!(d));
        }
        decl.insert(
            "capability".into(),
            json!(capability(self.ev, cfg, &o, span, "a dashboard")?),
        );

        if let Some(home) = o.get("home") {
            let Value::Bool(b) = home else {
                return Err(Diag::new(
                    self.at(cfg, "home", span),
                    format!("`home` must be true or false, not {}", home.describe()),
                ));
            };
            if *b {
                decl.insert("home".into(), json!(true));
            }
        }

        if let Some(mv) = o.get("menu") {
            let mspan = self.at(cfg, "menu", span);
            match mv {
                Value::Bool(false) => {
                    decl.insert("menu".into(), json!(false));
                }
                Value::Object(_) => {
                    let mo = options(mv, mspan, "`menu`", &["icon", "section"], self.ev)?;
                    let mut m = Map::new();
                    for k in ["icon", "section"] {
                        if let Some(val) = text(&mo, k, self.at(mv, k, mspan), "`menu`")? {
                            m.insert(k.into(), json!(val));
                        }
                    }
                    decl.insert("menu".into(), Json::Object(m));
                }
                other => {
                    return Err(Diag::new(
                        mspan,
                        format!("`menu` is an object or `false`, not {}", other.describe()),
                    ));
                }
            }
        }

        let wspan = self.at(cfg, "widgets", span);
        let Some(Value::Array(items)) = o.get("widgets") else {
            return Err(Diag::new(wspan, "adminDashboard() needs a `widgets` list")
                .help("write `widgets: [\"sp/content-overview\", myWidget]`"));
        };
        let mut placements = Vec::new();
        for (idx, item) in items.borrow().iter().enumerate() {
            let mut placement = Map::new();
            match item {
                Value::Object(_) => {
                    let po = options(
                        item,
                        wspan,
                        "a dashboard widget placement",
                        DASHBOARD_WIDGET_ITEM_KEYS,
                        self.ev,
                    )?;
                    let Some(wval) = po.get("widget") else {
                        return Err(Diag::new(wspan, "a widget placement object needs `widget`"));
                    };
                    let wtype = self.resolve_widget_type(wval, self.at(item, "widget", wspan))?;
                    placement.insert("uid".into(), json!(format!("w-{idx}-{wtype}")));
                    placement.insert("type".into(), json!(wtype));
                    if let Some(w) = po.get("width") {
                        placement.insert(
                            "width".into(),
                            json!(span_int(w, self.at(item, "width", wspan), "width")?),
                        );
                    }
                    if let Some(h) = po.get("height") {
                        placement.insert(
                            "height".into(),
                            json!(span_int(h, self.at(item, "height", wspan), "height")?),
                        );
                    }
                    if let Some(fw) = po.get("fullWidth") {
                        let Value::Bool(b) = fw else {
                            return Err(Diag::new(
                                self.at(item, "fullWidth", wspan),
                                "`fullWidth` must be true or false",
                            ));
                        };
                        if *b {
                            placement.insert("fullWidth".into(), json!(true));
                        }
                    }
                }
                other => {
                    let wtype = self.resolve_widget_type(other, wspan)?;
                    placement.insert("uid".into(), json!(format!("w-{idx}-{wtype}")));
                    placement.insert("type".into(), json!(wtype));
                }
            }
            placement.insert("order".into(), json!(idx));
            placements.push(Json::Object(placement));
        }
        if placements.is_empty() {
            return Err(Diag::new(
                wspan,
                "adminDashboard() `widgets` must list at least one widget",
            ));
        }
        decl.insert("widgets".into(), Json::Array(placements));
        Ok(Json::Object(decl))
    }
}
