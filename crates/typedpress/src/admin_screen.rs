//! `adminScreen({ ... })`: a TypedPress Admin screen declared in TypeScript (docs/admin-screens.md).
//!
//! A screen registers by its export like a kind or an action. Extraction checks it against the
//! actions it names and compiles it to the declaration `tp_admin_register_screen()` takes
//! (runtime/admin/lib/screens.php), with each action reference replaced by the name of the Ability
//! the runtime registers for it (`<app>/<kebab action>`). The declaration goes into schema.php
//! (`screens`), and the app plugin registers it on `tp_admin_screens` (TypedPress\Wp\Plugin), so a
//! TypeScript screen and a PHP one end up in the same registry.

use crate::diag::{Diag, Result, Span, suggest};
use crate::eval::{Evaluator, to_json};
use crate::extract::Extracted;
use crate::schema::Action;
use crate::value::{ActionVal, Value};
use crate::wordpress::abilities::kebab;
use indexmap::IndexMap;
use serde_json::{Map, Value as Json, json};
use std::rc::Rc;

const SCREEN_KEYS: &[&str] = &[
    "title",
    "description",
    "access",
    "capability",
    "classic",
    "menu",
    "view",
    "form",
    "actions",
    "data",
];
const VIEW_KEYS: &[&str] = &["action", "input", "items", "id", "fields", "layout"];
const FORM_KEYS: &[&str] = &["action", "input", "item", "fields", "layout", "submit"];
const ACTION_KEYS: &[&str] = &["id", "label", "action", "input", "destructive", "confirm"];
const FIELD_KEYS: &[&str] = &["label", "type", "elements", "description"];
const FIELD_TYPES: &[&str] = &["text", "integer", "number", "boolean", "datetime", "array"];
const LAYOUT_TYPES: &[&str] = &["table", "grid", "list"];
/// `access` as TypedPress's roles, read as the WordPress capability each one stands for (the ladder
/// TypedPress\Wp\WpAuth::map reads back).
const ROLES: &[(&str, &str)] = &[
    ("admin", "manage_options"),
    ("editor", "edit_others_posts"),
    ("author", "edit_posts"),
    ("subscriber", "read"),
    ("authenticated", "read"),
];
/// Screen ids TypedPress Admin and the runtime register themselves (runtime/admin/lib/screens.php,
/// TypedPress\RuntimeSchema\Surfaces).
const RESERVED: &[&str] = &["schema-versions"];

/// Every exported `adminScreen()` (in `cfg.screens`), into
/// `out.schema.screens` by id. Runs after the actions are extracted, since a screen names them.
pub fn screens(v: &Value, span: Span, ev: &Evaluator<'_>, out: &mut Extracted) -> Result<()> {
    let Value::Object(entries) = v else {
        return Err(Diag::new(
            span,
            "`screens` must be an object of adminScreen(...) values",
        ));
    };
    let app = kebab(&out.schema.name);
    for (name, value) in entries.borrow().iter() {
        let nspan = ev.key_span(v, name).unwrap_or(span);
        let Value::Screen(screen) = value else {
            return Err(Diag::new(
                nspan,
                format!("`{name}` must be wrapped in adminScreen({{ ... }})"),
            ));
        };
        let id = kebab(name);
        if RESERVED.contains(&id.as_str()) || id.starts_with("table-") {
            return Err(Diag::new(
                screen.span,
                format!("the screen id `{id}` is one TypedPress registers itself"),
            )
            .help("export the screen under another name; `table-*` and `schema-versions` are TypedPress's own"));
        }
        if out.schema.screens.contains_key(&id) {
            return Err(
                Diag::new(screen.span, format!("two screens are both registered as `{id}`"))
                    .help("a screen's id is its export name in kebab case; rename one export"),
            );
        }
        let declaration = Screen { ev, out, app: &app }.declaration(screen)?;
        out.schema.screens.insert(id, declaration);
    }
    Ok(())
}

struct Screen<'a, 'b, 'c> {
    ev: &'a Evaluator<'b>,
    out: &'a Extracted,
    app: &'c str,
}

/// One object option, with the span of its key (or the object's) for errors.
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

fn is_placeholder(s: &str) -> Option<&str> {
    s.strip_prefix("{item.").and_then(|r| r.strip_suffix('}'))
}

impl Screen<'_, '_, '_> {
    fn at(&self, obj: &Value, key: &str, fallback: Span) -> Span {
        self.ev.key_span(obj, key).unwrap_or(fallback)
    }

    fn declaration(&self, screen: &Rc<ActionVal>) -> Result<Json> {
        let cfg = &screen.config;
        let span = screen.span;
        let o = options(cfg, span, "adminScreen()", SCREEN_KEYS, self.ev)?;
        let mut decl = Map::new();
        let Some(title) = text(&o, "title", self.at(cfg, "title", span), "the screen's")? else {
            return Err(Diag::new(span, "adminScreen() needs a `title`")
                .help("add `title: \"Pending orders\"`: the page title in both admins"));
        };
        decl.insert("title".into(), json!(title));
        if let Some(d) = text(&o, "description", self.at(cfg, "description", span), "the screen's")? {
            decl.insert("description".into(), json!(d));
        }
        decl.insert("capability".into(), json!(self.capability(cfg, &o, span)?));
        for (key, keys) in [
            ("classic", &["parent", "icon", "position"][..]),
            ("menu", &["parent", "icon"][..]),
        ] {
            let kspan = self.at(cfg, key, span);
            match o.get(key) {
                None => {}
                Some(Value::Bool(false)) => {
                    decl.insert(key.into(), json!(false));
                }
                Some(v @ Value::Object(_)) => {
                    let m = options(v, kspan, &format!("`{key}`"), keys, self.ev)?;
                    let mut place = Map::new();
                    for (k, value) in &m {
                        let vspan = self.at(v, k, kspan);
                        let ok = match (k.as_str(), value) {
                            ("position", Value::Num(n)) => n.is_finite(),
                            ("position", _) => false,
                            (_, Value::Str(s)) => !s.is_empty(),
                            _ => false,
                        };
                        if !ok {
                            return Err(Diag::new(vspan, format!("`{key}.{k}` is {}", value.describe())).help(
                                if k == "position" {
                                    "write a number: the menu position wp-admin takes".to_string()
                                } else {
                                    format!(
                                        "write a string, such as `{k}: \"{}\"`",
                                        if k == "icon" { "dashicons-cart" } else { "tools.php" }
                                    )
                                },
                            ));
                        }
                        place.insert(k.clone(), to_json(value).unwrap_or(Json::Null));
                    }
                    decl.insert(key.into(), Json::Object(place));
                }
                Some(other) => {
                    return Err(Diag::new(
                        kspan,
                        format!("`{key}` is an object or `false`, not {}", other.describe()),
                    )
                    .help(format!(
                        "write `{key}: {{ icon: \"dashicons-cart\" }}`, or `{key}: false` to leave it out"
                    )));
                }
            }
        }
        let (view, form) = (o.get("view"), o.get("form"));
        let fields: Vec<String> = match (view, form) {
            (Some(v), None) => {
                let (body, fields) = self.source(v, self.at(cfg, "view", span), "view", VIEW_KEYS)?;
                decl.insert("view".into(), body);
                fields
            }
            (None, Some(f)) => {
                let (body, fields) = self.source(f, self.at(cfg, "form", span), "form", FORM_KEYS)?;
                decl.insert("form".into(), body);
                fields
            }
            (Some(_), Some(_)) => {
                return Err(Diag::new(
                    self.at(cfg, "form", span),
                    "a screen has a `view` or a `form`, not both",
                )
                .help("make two screens, one for the list and one for the form"));
            }
            (None, None) => {
                return Err(Diag::new(span, "adminScreen() needs a `view` (a list) or a `form`")
                    .help("add `view: { action: listOrders, fields: { ... } }`, where the action is readonly and returns the rows"));
            }
        };
        // `{item.x}` names a field the screen declares, or the list's item key.
        let item_keys: Vec<String> = match view.and_then(|v| match v {
            Value::Object(o) => o.borrow().get("id").cloned(),
            _ => None,
        }) {
            Some(Value::Str(id)) => fields.iter().cloned().chain([id.to_string()]).collect(),
            _ if view.is_some() => fields.iter().cloned().chain(["id".to_string()]).collect(),
            _ => fields.clone(),
        };
        if let (Some(f), Some(Json::Object(form))) = (form, decl.get_mut("form"))
            && let Value::Object(fo) = f
            && let Some(submit) = fo.borrow().get("submit").filter(|v| !matches!(v, Value::Undef))
        {
            let sspan = self.at(f, "submit", span);
            form.insert("submit".into(), self.action(submit, sspan, &item_keys)?);
        }
        if let Some(list) = o.get("actions") {
            let aspan = self.at(cfg, "actions", span);
            let Value::Array(items) = list else {
                return Err(Diag::new(aspan, format!("`actions` is a list, not {}", list.describe()))
                    .help("write `actions: [{ id: \"ship\", label: \"Ship\", action: ship, input: { id: item(\"id\") } }]`"));
            };
            let mut actions = Vec::new();
            for a in items.borrow().iter() {
                let decl = self.action(a, aspan, &item_keys)?;
                let id = decl["id"].as_str().unwrap_or_default().to_string();
                if actions.iter().any(|d: &Json| d["id"] == json!(id)) {
                    return Err(
                        Diag::new(aspan, format!("two actions on this screen have the id `{id}`"))
                            .help("give each action its own `id`"),
                    );
                }
                actions.push(decl);
            }
            decl.insert("actions".into(), Json::Array(actions));
        }
        if let Some(data) = o.get("data") {
            let dspan = self.at(cfg, "data", span);
            let ok = match data {
                Value::Str(_) => true,
                Value::Array(a) => a.borrow().iter().all(|v| matches!(v, Value::Str(_))),
                _ => false,
            };
            if !ok {
                return Err(Diag::new(dspan, format!("`data` is a string or a list of strings, not {}", data.describe()))
                    .help("write `data: [\"Shipping runs at 4pm.\"]`; a value computed per request needs a PHP screen (docs/admin-screens.md)"));
            }
            decl.insert("data".into(), to_json(data).unwrap_or(Json::Null));
        }
        Ok(Json::Object(decl))
    }

    /// `access` (a TypedPress role) or `capability` (a WordPress capability); default administrators.
    fn capability(&self, cfg: &Value, o: &IndexMap<String, Value>, span: Span) -> Result<String> {
        match (o.get("access"), o.get("capability")) {
            (Some(_), Some(_)) => Err(Diag::new(self.at(cfg, "capability", span), "a screen has `access` or `capability`, not both")
                .help("keep `access: \"editor\"` (a TypedPress role) or `capability: \"edit_others_posts\"` (a WordPress capability)")),
            (Some(Value::Str(role)), None) => ROLES
                .iter()
                .find(|(r, _)| *r == role.as_ref())
                .map(|(_, cap)| cap.to_string())
                .ok_or_else(|| {
                    let mut d = Diag::new(self.at(cfg, "access", span), format!("`access: \"{role}\"` isn't a role an admin screen takes"));
                    d = match suggest(role, ROLES.iter().map(|(r, _)| *r)) {
                        Some(s) => d.help(format!("did you mean `\"{s}\"`?")),
                        None => d.help("use \"admin\", \"editor\", \"author\", \"subscriber\" or \"authenticated\"; an admin screen needs a signed-in user"),
                    };
                    d
                }),
            (None, Some(Value::Str(cap))) if !cap.is_empty() => Ok(cap.to_string()),
            (Some(other), None) | (None, Some(other)) => Err(Diag::new(
                self.at(cfg, if o.contains_key("access") { "access" } else { "capability" }, span),
                format!("a screen's access is a string, not {}", other.describe()),
            )
            .help("write `access: \"editor\"` or `capability: \"edit_others_posts\"`; each action still checks its own access when it runs")),
            (None, None) => Ok("manage_options".into()),
        }
    }

    /// The registered action a value refers to.
    fn action_ref(&self, v: Option<&Value>, span: Span, what: &str) -> Result<(&Action, String)> {
        let name = match v {
            Some(Value::Action(a)) => self.out.action_values.get(&(Rc::as_ptr(a) as usize)),
            _ => None,
        };
        let Some(name) = name else {
            let got = v.map_or_else(|| "missing".to_string(), |v| v.describe());
            let mut d = Diag::new(span, format!("{what} names an action, but it is {got}"));
            d = match v {
                Some(Value::Str(s)) => match suggest(s, self.out.schema.actions.keys().map(String::as_str)) {
                    Some(s) => d.help(format!(
                        "pass the action itself, not its name: `action: {s}` (import it from where it's exported)"
                    )),
                    None => d.help(
                        "pass an exported action itself: `action: listOrders`, imported from where it's exported",
                    ),
                },
                _ => d.help("pass an exported action: `action: listOrders`, imported from where it's exported"),
            };
            return Err(d);
        };
        let action = &self.out.schema.actions[name];
        if !action.agents {
            return Err(Diag::new(
                span,
                format!("the action `{name}` is `agents: false`, so it has no Ability a screen can run"),
            )
            .help(format!(
                "remove `agents: false` from `{name}`; its `access` still decides who may run it"
            )));
        }
        if action.signature.is_some() {
            return Err(Diag::new(
                span,
                format!("the action `{name}` takes a signed request, which a screen can't send"),
            )
            .help("use an action with `access` instead of `signature`"));
        }
        Ok((action, format!("{}/{}", self.app, kebab(name))))
    }

    /// An action's `input`: plain data whose keys are the action's input fields, with every required
    /// field given. `{item.x}` placeholders (`item("x")`) must name an item key when `item_keys` has
    /// any; a view's own input has no item.
    fn input(
        &self,
        parent: &Value,
        o: &IndexMap<String, Value>,
        span: Span,
        action: &Action,
        item_keys: Option<&[String]>,
    ) -> Result<Option<Json>> {
        let ispan = self.at(parent, "input", span);
        let input = match o.get("input") {
            None => None,
            Some(v @ Value::Object(_)) => Some((
                v,
                to_json(v).ok_or_else(|| {
                    Diag::new(ispan, "`input` must be plain data")
                        .help("use strings, numbers, booleans, lists, objects and item(\"field\")")
                })?,
            )),
            Some(other) => {
                return Err(
                    Diag::new(ispan, format!("`input` is an object, not {}", other.describe()))
                        .help("write `input: { id: item(\"id\") }`"),
                );
            }
        };
        let given: Vec<String> = input
            .as_ref()
            .and_then(|(_, j)| j.as_object())
            .map(|m| m.keys().cloned().collect())
            .unwrap_or_default();
        if let Some((v, json)) = &input {
            for (key, value) in json.as_object().into_iter().flatten() {
                let kspan = self.at(v, key, ispan);
                if !action.input.contains_key(key) {
                    let mut d = Diag::new(kspan, format!("the action `{}` has no input `{key}`", action.name));
                    d = match suggest(key, action.input.keys().map(String::as_str)) {
                        Some(s) => d.help(format!("did you mean `{s}`?")),
                        None if action.input.is_empty() => {
                            d.help(format!("`{}` takes no input; remove `{key}`", action.name))
                        }
                        None => d.help(format!(
                            "its inputs are {}",
                            action
                                .input
                                .keys()
                                .map(|k| format!("`{k}`"))
                                .collect::<Vec<_>>()
                                .join(", ")
                        )),
                    };
                    return Err(d);
                }
                self.placeholders(value, kspan, item_keys)?;
            }
        }
        if let Some((field, _)) = action
            .input
            .iter()
            .find(|(k, c)| !c.optional_on_insert() && !given.contains(k))
        {
            let fill = match item_keys {
                Some(keys) if keys.contains(field) => format!("item(\"{field}\")"),
                _ => "...".into(),
            };
            return Err(
                Diag::new(ispan, format!("the action `{}` needs the input `{field}`", action.name))
                    .help(format!("add it: `input: {{ {field}: {fill} }}`")),
            );
        }
        Ok(input.map(|(_, j)| j))
    }

    fn placeholders(&self, value: &Json, span: Span, item_keys: Option<&[String]>) -> Result<()> {
        match value {
            Json::String(s) => {
                let Some(path) = is_placeholder(s) else { return Ok(()) };
                let Some(keys) = item_keys else {
                    return Err(Diag::new(span, format!("`item(\"{path}\")` has no item here"))
                        .help("the list's own input runs before there are rows; give a fixed value"));
                };
                let head = path.split('.').next().unwrap_or_default();
                if !keys.iter().any(|k| k == head) {
                    let mut d = Diag::new(
                        span,
                        format!("`item(\"{path}\")` names `{head}`, which isn't a field of this screen"),
                    );
                    d = match suggest(head, keys.iter().map(String::as_str)) {
                        Some(s) => d.help(format!("did you mean `item(\"{s}\")`?")),
                        None => d.help(format!("declare `{head}` in the screen's `fields`")),
                    };
                    return Err(d);
                }
                Ok(())
            }
            Json::Array(items) => items.iter().try_for_each(|v| self.placeholders(v, span, item_keys)),
            Json::Object(m) => m.values().try_for_each(|v| self.placeholders(v, span, item_keys)),
            _ => Ok(()),
        }
    }

    /// `view` or `form`: the readonly action that supplies the data, its fields and layout.
    fn source(&self, v: &Value, span: Span, what: &str, keys: &[&str]) -> Result<(Json, Vec<String>)> {
        let o = options(v, span, &format!("`{what}`"), keys, self.ev)?;
        let aspan = self.at(v, "action", span);
        let (action, ability) = self.action_ref(o.get("action"), aspan, &format!("`{what}.action`"))?;
        if !action.readonly {
            return Err(Diag::new(
                aspan,
                format!("`{what}.action` runs `{}`, which isn't readonly", action.name),
            )
            .help(format!(
                "a screen reads its {} through a readonly action: add `readonly: true` to `{}`",
                if what == "view" { "rows" } else { "values" },
                action.name
            )));
        }
        let mut body = Map::new();
        body.insert("ability".into(), json!(ability));
        if let Some(input) = self.input(v, &o, span, action, None)? {
            body.insert("input".into(), input);
        }
        let path_key = if what == "view" { "items" } else { "item" };
        if let Some(path) = text(&o, path_key, self.at(v, path_key, span), &format!("`{what}`"))? {
            body.insert(path_key.into(), json!(path));
        }
        if let Some(id) = text(&o, "id", self.at(v, "id", span), "`view`")? {
            body.insert("id".into(), json!(id));
        }
        let fspan = self.at(v, "fields", span);
        let Some(fv @ Value::Object(fo)) = o.get("fields") else {
            return Err(
                Diag::new(fspan, format!("`{what}` needs `fields`, an object keyed by field id")).help(
                    "write `fields: { id: { label: \"Order\" }, total: { label: \"Total\", type: \"number\" } }`",
                ),
            );
        };
        let mut fields = Vec::new();
        let mut ids = Vec::new();
        for (id, f) in fo.borrow().iter() {
            let idspan = self.at(fv, id, fspan);
            let fo = options(f, idspan, &format!("field `{id}`"), FIELD_KEYS, self.ev)?;
            let mut field = Map::new();
            field.insert("id".into(), json!(id));
            if let Some(label) = text(&fo, "label", self.at(f, "label", idspan), &format!("field `{id}`"))? {
                field.insert("label".into(), json!(label));
            }
            if let Some(ty) = fo.get("type") {
                let tspan = self.at(f, "type", idspan);
                match ty {
                    Value::Str(t) if FIELD_TYPES.contains(&t.as_ref()) => {
                        field.insert("type".into(), json!(t.as_ref()));
                    }
                    _ => {
                        let got = match ty {
                            Value::Str(t) => t.to_string(),
                            other => other.describe(),
                        };
                        let mut d = Diag::new(
                            tspan,
                            format!("field `{id}` has the type `{got}`, which DataViews doesn't show"),
                        );
                        d = match ty {
                            Value::Str(t) if suggest(t, FIELD_TYPES.iter().copied()).is_some() => d.help(format!(
                                "did you mean `\"{}\"`?",
                                suggest(t, FIELD_TYPES.iter().copied()).unwrap_or_default()
                            )),
                            _ => d.help(format!("use one of {}", FIELD_TYPES.join(", "))),
                        };
                        return Err(d);
                    }
                }
            }
            if let Some(d) = text(
                &fo,
                "description",
                self.at(f, "description", idspan),
                &format!("field `{id}`"),
            )? {
                field.insert("description".into(), json!(d));
            }
            if let Some(el) = fo.get("elements") {
                let espan = self.at(f, "elements", idspan);
                let json = to_json(el).filter(|j| {
                    j.as_array().is_some_and(|a| {
                        a.iter().all(|e| {
                            e.get("label").is_some_and(Json::is_string)
                                && e.get("value").is_some_and(|v| v.is_string() || v.is_number())
                        })
                    })
                });
                let Some(json) = json else {
                    return Err(Diag::new(
                        espan,
                        format!("field `{id}`'s `elements` is a list of `{{ value, label }}`"),
                    )
                    .help("write `elements: [{ value: \"open\", label: \"Open\" }]`"));
                };
                field.insert("elements".into(), json);
            }
            fields.push(Json::Object(field));
            ids.push(id.clone());
        }
        if ids.is_empty() {
            return Err(Diag::new(fspan, format!("`{what}.fields` declares no fields"))
                .help("add at least one: `fields: { id: { label: \"Order\" } }`"));
        }
        body.insert("fields".into(), Json::Array(fields));
        if let Some(layout) = o.get("layout") {
            body.insert(
                "layout".into(),
                self.layout(layout, self.at(v, "layout", span), what, &ids)?,
            );
        }
        Ok((Json::Object(body), ids))
    }

    /// A DataViews view or DataForm form: every field it names is declared in `fields`.
    fn layout(&self, v: &Value, span: Span, what: &str, ids: &[String]) -> Result<Json> {
        let json = to_json(v).filter(Json::is_object).ok_or_else(|| {
            Diag::new(span, "`layout` is an object").help("write `layout: { type: \"table\", fields: [\"total\"] }`")
        })?;
        let m = json.as_object().expect("an object");
        let check = |name: &str, key: &str| -> Result<()> {
            if ids.iter().any(|i| i == name) {
                return Ok(());
            }
            let mut d = Diag::new(
                self.at(v, key, span),
                format!("`{what}.layout.{key}` names `{name}`, which isn't in `{what}.fields`"),
            );
            d = match suggest(name, ids.iter().map(String::as_str)) {
                Some(s) => d.help(format!("did you mean `\"{s}\"`?")),
                None => d.help(format!(
                    "declare `{name}` in `fields`, or use one of {}",
                    ids.join(", ")
                )),
            };
            Err(d)
        };
        if what == "view"
            && let Some(t) = m.get("type")
            && !t.as_str().is_some_and(|t| LAYOUT_TYPES.contains(&t))
        {
            return Err(Diag::new(
                self.at(v, "type", span),
                format!("`view.layout.type` is {t}, which DataViews doesn't have"),
            )
            .help("use \"table\", \"grid\" or \"list\""));
        }
        for key in ["titleField", "mediaField", "descriptionField"] {
            if let Some(name) = m.get(key) {
                check(name.as_str().unwrap_or_default(), key)?;
            }
        }
        if let Some(fields) = m.get("fields") {
            let Some(list) = fields.as_array() else {
                return Err(
                    Diag::new(self.at(v, "fields", span), "`layout.fields` is a list of field ids")
                        .help("write `fields: [\"total\", \"shipped\"]`"),
                );
            };
            for f in list {
                // A DataForm layout may group fields: `{ id, children }` names its own fields.
                match f {
                    Json::String(name) => check(name, "fields")?,
                    Json::Object(group) if what == "form" => {
                        for c in group.get("children").and_then(Json::as_array).into_iter().flatten() {
                            if let Some(name) = c.as_str() {
                                check(name, "fields")?;
                            }
                        }
                    }
                    other => {
                        return Err(Diag::new(
                            self.at(v, "fields", span),
                            format!("`layout.fields` lists field ids, not {other}"),
                        )
                        .help("write `fields: [\"total\", \"shipped\"]`"));
                    }
                }
            }
        }
        if let Some(field) = m.get("sort").and_then(|s| s.get("field")) {
            check(field.as_str().unwrap_or_default(), "sort")?;
        }
        Ok(json)
    }

    /// An item action, or a form's `submit`: one action run as its Ability.
    fn action(&self, v: &Value, span: Span, item_keys: &[String]) -> Result<Json> {
        let o = options(v, span, "a screen action", ACTION_KEYS, self.ev)?;
        let mut decl = Map::new();
        let Some(id) = text(&o, "id", self.at(v, "id", span), "a screen action's")? else {
            return Err(Diag::new(span, "a screen action needs an `id`").help("add `id: \"ship\"`"));
        };
        if !id.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_') {
            return Err(Diag::new(
                self.at(v, "id", span),
                format!("the action id `{id}` isn't a plain name"),
            )
            .help("use letters, digits, `-` and `_`"));
        }
        let Some(label) = text(&o, "label", self.at(v, "label", span), "a screen action's")? else {
            return Err(Diag::new(span, format!("the screen action `{id}` needs a `label`"))
                .help("add `label: \"Mark shipped\"`"));
        };
        let (action, ability) = self.action_ref(
            o.get("action"),
            self.at(v, "action", span),
            &format!("the screen action `{id}`"),
        )?;
        decl.insert("id".into(), json!(id));
        decl.insert("label".into(), json!(label));
        decl.insert("ability".into(), json!(ability));
        if let Some(input) = self.input(v, &o, span, action, Some(item_keys))? {
            decl.insert("input".into(), input);
        }
        let destructive = match o.get("destructive") {
            None => action.destructive,
            Some(Value::Bool(b)) => *b,
            Some(other) => {
                return Err(Diag::new(
                    self.at(v, "destructive", span),
                    format!("`destructive` is true or false, not {}", other.describe()),
                )
                .help("leave it out to take the action's own `destructive`"));
            }
        };
        if destructive {
            decl.insert("destructive".into(), json!(true));
        }
        if let Some(confirm) = text(&o, "confirm", self.at(v, "confirm", span), "a screen action's")? {
            decl.insert("confirm".into(), json!(confirm));
        }
        Ok(Json::Object(decl))
    }
}
