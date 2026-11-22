//! Native Woo kinds: fixed shapes over Woo's objects, with the shared kind API and sync contract.
use crate::diag::{Diag, Result, Span};
use crate::eval::to_json;
use crate::schema::*;
use crate::value::{CollectionVal, StorageKind, Value};
use indexmap::IndexMap;
use serde_json::{Value as Json, json};

pub fn store(config: &IndexMap<String, Value>, schema: &mut Schema, span: Span) -> Result<()> {
    let stores = match config.get("stores") {
        Some(Value::Object(o)) => o.borrow().values().cloned().collect::<Vec<_>>(),
        _ => vec![],
    };
    if stores.len() > 1 {
        return Err(Diag::new(span, "at most one store() is allowed"));
    }
    if stores.is_empty() {
        if schema.collections.values().any(|c| c.commerce.is_some()) {
            return Err(Diag::new(span, "commerce kinds require an exported store()"));
        }
        return Ok(());
    }
    let Value::Store(value) = &stores[0] else {
        return Err(Diag::new(span, "stores entries must be declared with store()"));
    };
    let data = to_json(&value.config).ok_or_else(|| Diag::new(value.span, "store settings must be static data"))?;
    validate_store(&data).map_err(|e| Diag::new(value.span, e))?;
    if schema
        .collections
        .values()
        .any(|c| c.key == "store" || c.table == "store")
    {
        return Err(Diag::new(span, "store is reserved for the store information API"));
    }
    schema.store = Some(data);
    Ok(())
}

fn object<'a>(v: &'a Json, keys: &[&str], at: &str) -> std::result::Result<&'a serde_json::Map<String, Json>, String> {
    let o = v.as_object().ok_or_else(|| format!("{at} must be an object"))?;
    if let Some(key) = o.keys().find(|k| !keys.contains(&k.as_str())) {
        return Err(format!("{at} has no option `{key}`"));
    }
    Ok(o)
}
fn text<'a>(v: Option<&'a Json>, at: &str) -> std::result::Result<&'a str, String> {
    v.and_then(Json::as_str)
        .filter(|s| !s.is_empty())
        .ok_or_else(|| format!("{at} must be a nonempty string"))
}
fn countries(v: &Json, rest: bool) -> std::result::Result<(), String> {
    if v == if rest { "rest" } else { "all" } {
        return Ok(());
    }
    let list = v
        .as_array()
        .filter(|a| !a.is_empty())
        .ok_or("countries must be a nonempty country list")?;
    for c in list {
        if !country(c.as_str().unwrap_or("")) {
            return Err(format!("unknown country {c}"));
        }
    }
    Ok(())
}
fn country(c: &str) -> bool {
    COUNTRIES.split_whitespace().any(|s| s == c)
}
const COUNTRIES: &str = "AF AX AL DZ AS AD AO AI AQ AG AR AM AW AU AT AZ BS BH BD BB BY BE BZ BJ BM BT BO BQ BA BW BV BR IO BN BG BF BI CV KH CM CA KY CF TD CL CN CX CC CO KM CG CD CK CR CI HR CU CW CY CZ DK DJ DM DO EC EG SV GQ ER EE SZ ET FK FO FJ FI FR GF PF TF GA GM GE DE GH GI GR GL GD GP GU GT GG GN GW GY HT HM VA HN HK HU IS IN ID IR IQ IE IM IL IT JM JP JE JO KZ KE KI KP KR KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MQ MR MU YT MX FM MD MC MN ME MS MA MZ MM NA NR NP NL NC NZ NI NE NG NU NF MK MP NO OM PK PW PS PA PG PY PE PH PN PL PT PR QA RE RO RU RW BL SH KN LC MF PM VC WS SM ST SA SN RS SC SL SG SX SK SI SB SO ZA GS SS ES LK SD SR SJ SE CH SY TW TJ TZ TH TL TG TK TO TT TN TR TM TC TV UG UA AE GB US UM UY UZ VU VE VN VG VI WF EH YE ZM ZW";
fn money(v: &Json) -> bool {
    v.as_str()
        .is_some_and(|s| regex::Regex::new(r"^[0-9]+(?:\.[0-9]+)?$").unwrap().is_match(s))
}
fn has_percent_escape(name: &str) -> bool {
    name.as_bytes()
        .windows(3)
        .any(|s| s[0] == b'%' && s[1].is_ascii_hexdigit() && s[2].is_ascii_hexdigit())
}
fn validate_store(v: &Json) -> std::result::Result<(), String> {
    let o = object(
        v,
        &[
            "currency", "country", "address", "sellTo", "units", "shipping", "payments", "email", "checkout",
        ],
        "store()",
    )?;
    let currency = text(o.get("currency"), "currency")?;
    // Woo's supported ISO currencies (not arbitrary three-letter strings).
    if !CURRENCIES.split_whitespace().any(|c| c == currency) {
        return Err(format!("unknown currency {currency}"));
    }
    let location = text(o.get("country"), "country")?;
    if !country(location.split(':').next().unwrap()) || location.ends_with(':') {
        return Err(format!("unknown country {location}"));
    }
    if let Some(a) = o.get("address") {
        let a = object(a, &["line1", "line2", "city", "postcode"], "address")?;
        for k in ["line1", "city", "postcode"] {
            text(a.get(k), k)?;
        }
    }
    if let Some(c) = o.get("sellTo") {
        countries(c, false)?;
    }
    if let Some(u) = o.get("units") {
        let u = object(u, &["weight", "dimension"], "units")?;
        for (k, allowed) in [
            ("weight", &["kg", "g", "lbs", "oz"][..]),
            ("dimension", &["m", "cm", "mm", "in", "yd"][..]),
        ] {
            if !allowed.contains(&text(u.get(k), k)?) {
                return Err(format!("unknown {k} unit"));
            }
        }
    }
    if let Some(zones) = o.get("shipping") {
        let zones = zones.as_array().ok_or("shipping must be an array")?;
        let mut names = std::collections::HashSet::new();
        let mut rest = false;
        for (index, zone) in zones.iter().enumerate() {
            let z = object(zone, &["name", "countries", "methods"], "shipping zone")?;
            let name = text(z.get("name"), "zone name")?;
            if name.trim() != name {
                return Err("shipping zone names cannot start or end with whitespace".into());
            }
            if !names.insert(name) {
                return Err("shipping zone names must be unique".into());
            }
            let c = z.get("countries").ok_or("shipping zone needs countries")?;
            countries(c, true)?;
            if c != "rest"
                && (name.starts_with('\0')
                    || name.ends_with('\0')
                    || name.contains("  ")
                    || name.contains(['\t', '\n', '\r', '<'])
                    || has_percent_escape(name))
            {
                return Err("shipping zone name contains formatting WooCommerce would remove".into());
            }
            if c != "rest" && name == "0" {
                return Err("a named shipping zone cannot be called 0".into());
            }
            if c == "rest" && index + 1 != zones.len() {
                return Err("the rest shipping zone must be last".into());
            }
            if c == "rest" {
                if rest {
                    return Err("only one rest shipping zone is allowed".into());
                }
                rest = true;
            }
            for m in z
                .get("methods")
                .and_then(Json::as_array)
                .ok_or("zone methods must be an array")?
            {
                let m = object(m, &["type", "title", "cost", "minimum"], "shipping method")?;
                text(m.get("title"), "shipping title")?;
                let kind = text(m.get("type"), "shipping type")?;
                if !["flatRate", "freeShipping", "localPickup"].contains(&kind) {
                    return Err(format!("unknown shipping method {kind}"));
                }
                if kind == "flatRate" && !m.get("cost").is_some_and(money) {
                    return Err("flatRate cost must be an exact decimal string".into());
                }
                for k in ["cost", "minimum"] {
                    if m.get(k).is_some_and(|v| !money(v)) {
                        return Err(format!("{k} must be an exact decimal string"));
                    }
                }
                if (kind != "freeShipping" && m.contains_key("minimum"))
                    || (kind == "freeShipping" && m.contains_key("cost"))
                {
                    return Err("shipping method has an unsupported option".into());
                }
            }
        }
    }
    if let Some(p) = o.get("payments") {
        let p = p.as_array().ok_or("payments must be an array")?;
        for id in p {
            text(Some(id), "gateway id")?;
        }
    }
    if let Some(e) = o.get("email") {
        let e = object(e, &["fromName", "fromAddress"], "email")?;
        text(e.get("fromName"), "fromName")?;
        let address = text(e.get("fromAddress"), "fromAddress")?;
        if !address.contains('@') {
            return Err("fromAddress must be an email address".into());
        }
    }
    if let Some(p) = o.get("checkout") {
        let p = text(Some(p), "checkout")?;
        if !p.starts_with('/')
            || p.starts_with("//")
            || p.contains(['?', '#', ':', '[', ']', '\\'])
            || p.split('/').any(|s| s == "..")
            || p == "/"
        {
            return Err("checkout must be a static site-relative page path".into());
        }
    }
    Ok(())
}
const CURRENCIES: &str = "AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTC BTN BWP BYN BZD CAD CDF CHF CLP CNY COP CRC CUC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GGP GHS GIP GMD GNF GTQ GYD HKD HNL HRK HTG HUF IDR ILS IMP INR IQD IRR IRT ISK JEP JMD JOD JPY KES KGS KHR KMF KPW KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PRB PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLL SLE SOS SRD SSP STN SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD UYU UZS VEF VES VND VUV WST XAF XCD XOF XPF YER ZAR ZMW";

fn field(c: &mut Collection, name: &str, ty: ColType, required: bool, read: bool, default: Option<Json>) {
    let mut col = Column::new(name, ty);
    col.not_null = required;
    col.read_only = read;
    col.default = default.map(DefaultKind::Value);
    c.columns.insert(name.into(), col);
}
pub fn kind(value: &CollectionVal, schema: &mut Schema) -> Result<()> {
    let kind = value.kind;
    if schema
        .collections
        .values()
        .filter(|c| c.commerce.as_deref() == Some(kind.builder()))
        .count()
        > 1
    {
        return Err(Diag::new(
            value.span,
            format!("at most one {}() is allowed", kind.builder()),
        ));
    }
    let o = match &value.options {
        None => json!({}),
        Some(options) => {
            let Value::Object(options) = options else {
                unreachable!("validated by evaluator")
            };
            let mut options = options.borrow().clone();
            if kind == StorageKind::Product {
                options.shift_remove("fields");
            }
            to_json(&Value::object(options))
                .ok_or_else(|| Diag::new(value.span, "commerce options must be static data"))?
        }
    };
    let allowed = if kind == StorageKind::Order {
        &["api", "agents", "label"][..]
    } else if kind == StorageKind::Product {
        &["sync", "url", "api", "agents", "label", "inventory", "reviews"][..]
    } else {
        &["sync", "url", "api", "agents", "label"][..]
    };
    object(&o, allowed, kind.builder()).map_err(|e| Diag::new(value.span, e))?;
    let c = schema.collections.get_mut(value.table()).unwrap();
    c.editor = false;
    c.drafts = false;
    c.revisions = false;
    c.staged = false;
    c.hierarchical = kind == StorageKind::ProductCategory;
    let flag = |name| match o.get(name) {
        Some(Json::Bool(on)) => Ok(*on),
        None => Ok(false),
        _ => Err(Diag::new(value.span, format!("{name} must be a boolean"))),
    };
    c.comments = flag("reviews")?;
    if c.comments {
        c.comment_policy = Some(CommentPolicy {
            types: vec!["review".into()],
            ..CommentPolicy::default()
        });
    }
    c.uid = kind != StorageKind::Order;
    if let Some(label) = o.get("label") {
        c.label = text(Some(label), "label").map_err(|e| Diag::new(value.span, e))?.into();
    }
    if let Some(agents) = o.get("agents") {
        c.agents = agents
            .as_bool()
            .ok_or_else(|| Diag::new(value.span, "agents must be true or false"))?;
    }
    c.access = ["read", "create", "update", "delete"]
        .into_iter()
        .map(|op| {
            (
                op.into(),
                Access::Role(
                    if op == "read" && kind != StorageKind::Order {
                        "anyone"
                    } else {
                        "admin"
                    }
                    .into(),
                ),
            )
        })
        .collect();
    if kind == StorageKind::Order {
        c.api.create = false;
        c.api.update = false;
        c.api.delete = false;
        for op in ["create", "update", "delete"] {
            c.access.insert(op.into(), Access::Allow(false));
        }
    }
    if let Some(api) = o.get("api") {
        if api == &Json::Bool(false) {
            c.api = Api {
                list: false,
                get: false,
                create: false,
                update: false,
                delete: false,
            };
        } else {
            let a = object(api, &["list", "get", "create", "update", "delete"], "api")
                .map_err(|e| Diag::new(value.span, e))?;
            for (key, val) in a {
                let v = val
                    .as_bool()
                    .ok_or_else(|| Diag::new(value.span, "api operations must be booleans"))?;
                if kind == StorageKind::Order && v && !["list", "get"].contains(&key.as_str()) {
                    return Err(Diag::new(value.span, "order() is read-only"));
                }
                match key.as_str() {
                    "list" => c.api.list = v,
                    "get" => c.api.get = v,
                    "create" => c.api.create = v,
                    "update" => c.api.update = v,
                    _ => c.api.delete = v,
                }
            }
        }
    }
    if let Some(pattern) = o.get("sync") {
        let sync = crate::extract::sync_config(
            text(Some(pattern), "sync").map_err(|e| Diag::new(value.span, e))?,
            value.span,
        )?;
        if sync["nested"] == true && !c.hierarchical {
            return Err(Diag::new(value.span, "a `**` sync pattern needs a hierarchical kind")
                .help("use a flat pattern such as content/products/*.md"));
        }
        c.sync = Some(sync);
    }
    if let Some(url) = o.get("url") {
        let url = text(Some(url), "url").map_err(|e| Diag::new(value.span, e))?;
        let mut columns = IndexMap::new();
        columns.insert("slug".into(), Column::new("slug", ColType::Slug));
        columns.insert("id".into(), Column::new("id", ColType::Integer));
        crate::kind_url::check(
            url,
            &crate::kind_url::UrlKind {
                columns: &columns,
                dated: false,
                hierarchical: c.hierarchical,
                public_slug: &|_| false,
            },
        )
        .map_err(|e| Diag::new(value.span, e.message))?;
        c.url = Some(url.into());
    }
    let mut id = Column::new("id", ColType::Integer);
    id.primary_key = true;
    id.auto_increment = true;
    id.not_null = true;
    c.columns.insert("id".into(), id);
    if c.uid {
        field(c, "uid", ColType::Text, true, false, None);
        let uid = c.columns.get_mut("uid").unwrap();
        uid.system = true;
        uid.unique = true;
        uid.rules.max = Some(23.0);
    }
    if kind == StorageKind::Order {
        for (name, ty) in [
            ("status", ColType::Text),
            ("currency", ColType::Text),
            ("precision", ColType::Integer),
            ("createdAt", ColType::Timestamp),
            ("updatedAt", ColType::Timestamp),
            ("paidAt", ColType::Timestamp),
            ("completedAt", ColType::Timestamp),
            ("customer", ColType::Integer),
            ("paymentMethod", ColType::Text),
            ("paymentTitle", ColType::Text),
            ("total", ColType::Text),
            ("subtotal", ColType::Text),
            ("discountTotal", ColType::Text),
            ("shippingTotal", ColType::Text),
            ("taxTotal", ColType::Text),
            ("billing", ColType::Json),
            ("shipping", ColType::Json),
            ("lines", ColType::Json),
            ("shippingLines", ColType::Json),
            ("taxLines", ColType::Json),
            ("feeLines", ColType::Json),
            ("couponLines", ColType::Json),
            ("refunds", ColType::Json),
        ] {
            field(
                c,
                name,
                ty,
                !["paidAt", "completedAt", "customer"].contains(&name),
                true,
                None,
            );
        }
        for name in ["billing", "shipping"] {
            let col = c.columns.get_mut(name).unwrap();
            col.ty = ColType::Group;
            col.fields = Some(
                [
                    "first_name",
                    "last_name",
                    "company",
                    "address_1",
                    "address_2",
                    "city",
                    "state",
                    "postcode",
                    "country",
                    "email",
                    "phone",
                ]
                .into_iter()
                .map(|name| {
                    let mut column = Column::new(name, ColType::Text);
                    column.not_null = !["email", "phone"].contains(&name);
                    (name.into(), column)
                })
                .collect(),
            );
        }
        for (name, fields) in [
            (
                "lines",
                vec![
                    ("id", ColType::Integer),
                    ("name", ColType::Text),
                    ("product", ColType::Integer),
                    ("variation", ColType::Integer),
                    ("quantity", ColType::Integer),
                    ("subtotal", ColType::Text),
                    ("subtotalTax", ColType::Text),
                    ("total", ColType::Text),
                    ("totalTax", ColType::Text),
                    ("taxes", ColType::Json),
                ],
            ),
            (
                "shippingLines",
                vec![
                    ("id", ColType::Integer),
                    ("name", ColType::Text),
                    ("method_id", ColType::Text),
                    ("instance_id", ColType::Text),
                    ("total", ColType::Text),
                    ("total_tax", ColType::Text),
                    ("taxes", ColType::Json),
                ],
            ),
            (
                "taxLines",
                vec![
                    ("id", ColType::Integer),
                    ("name", ColType::Text),
                    ("rate_id", ColType::Integer),
                    ("label", ColType::Text),
                    ("compound", ColType::Boolean),
                    ("tax_total", ColType::Text),
                    ("shipping_tax_total", ColType::Text),
                ],
            ),
            (
                "feeLines",
                vec![
                    ("id", ColType::Integer),
                    ("name", ColType::Text),
                    ("total", ColType::Text),
                    ("total_tax", ColType::Text),
                    ("taxes", ColType::Json),
                ],
            ),
            (
                "couponLines",
                vec![
                    ("id", ColType::Integer),
                    ("name", ColType::Text),
                    ("code", ColType::Text),
                    ("discount", ColType::Text),
                    ("discount_tax", ColType::Text),
                ],
            ),
            (
                "refunds",
                vec![
                    ("id", ColType::Integer),
                    ("amount", ColType::Text),
                    ("reason", ColType::Text),
                    ("createdAt", ColType::Timestamp),
                ],
            ),
        ] {
            let col = c.columns.get_mut(name).unwrap();
            col.ty = ColType::Repeater;
            col.fields = Some(
                fields
                    .into_iter()
                    .map(|(name, ty)| {
                        let mut column = Column::new(name, ty);
                        column.not_null = name != "variation";
                        (name.into(), column)
                    })
                    .collect(),
            );
        }
    } else {
        field(c, "name", ColType::Text, true, false, None);
        field(c, "slug", ColType::Slug, true, false, None);
        c.columns.get_mut("slug").unwrap().source = Some("name".into());
        field(
            c,
            "description",
            if kind.term() { ColType::Text } else { ColType::RichText },
            true,
            false,
            Some(json!("")),
        );
        c.content = Some("description".into());
        c.title = Some("name".into());
        c.slug = Some("slug".into());
        if kind.term() {
            if c.hierarchical {
                field(c, "parent", ColType::Integer, false, false, None);
            }
            field(c, "count", ColType::Integer, true, true, None);
        } else {
            for name in ["sku", "shortDescription", "price"] {
                field(c, name, ColType::Text, false, false, None);
            }
            c.columns.get_mut("price").unwrap().rules.pattern = Some((r"^\d+(?:\.\d+)?$".into(), String::new()));
            field(c, "status", ColType::Text, true, false, Some(json!("published")));
            c.columns.get_mut("status").unwrap().enum_values = Some(
                ["draft", "pending", "scheduled", "published", "private"]
                    .map(String::from)
                    .into(),
            );
            field(c, "images", ColType::MediaList, true, false, Some(json!([])));
            for name in ["virtual", "downloadable"] {
                field(c, name, ColType::Boolean, true, false, Some(json!(false)));
            }
            field(c, "downloads", ColType::Repeater, true, false, Some(json!([])));
            c.columns.get_mut("downloads").unwrap().fields = Some(
                ["id", "name", "file"]
                    .into_iter()
                    .map(|name| {
                        let mut col = Column::new(name, ColType::Text);
                        col.not_null = true;
                        if name == "file" {
                            col.access.insert("read".into(), Access::Role("admin".into()));
                        }
                        (name.into(), col)
                    })
                    .collect(),
            );
            field(c, "type", ColType::Text, true, true, None);
            field(c, "purchasable", ColType::Boolean, true, true, None);
            field(c, "availability", ColType::Text, true, true, None);
            if flag("inventory")? {
                field(c, "stockQuantity", ColType::Integer, false, true, None);
            }
        }
        field(c, "url", ColType::Text, false, true, None);
    }
    Ok(())
}

pub fn relations(schema: &mut Schema) {
    for c in schema
        .collections
        .values_mut()
        .filter(|c| c.taxonomy.is_some() && c.uid && c.hierarchical)
    {
        if let Some(parent) = c.columns.get_mut("parent") {
            parent.references = Some(Reference {
                collection: c.table.clone(),
                column: "id".into(),
                on_delete: None,
                on_update: None,
                join: None,
            });
        }
    }
    let product = schema
        .collections
        .values()
        .find(|c| c.commerce.as_deref() == Some("product"))
        .map(|c| c.table.clone());
    let Some(product) = product else { return };
    let product_key = schema.collections[&product].key.clone();
    for (kind, name) in [("productCategory", "categories"), ("productTag", "tags")] {
        let term = schema
            .collections
            .values()
            .find(|c| c.commerce.as_deref() == Some(kind))
            .map(|c| c.table.clone());
        let Some(term) = term else { continue };
        let join = term_join(&term);
        let c = schema.collections.get_mut(&product).unwrap();
        let mut col = Column::new(name, ColType::RefList);
        col.not_null = true;
        col.default = Some(DefaultKind::Value(json!([])));
        col.references = Some(Reference {
            collection: term.clone(),
            column: "id".into(),
            on_delete: None,
            on_update: None,
            join: Some(join.clone()),
        });
        c.columns.insert(name.into(), col);
        c.relations.insert(
            name.into(),
            Relation {
                name: name.into(),
                kind: RelKind::Many,
                collection: term.clone(),
                from: "id".into(),
                to: "id".into(),
                optional: false,
                through: Some((join.clone(), "owner_id".into(), "target_id".into())),
            },
        );
        let c = schema.collections.get_mut(&term).unwrap();
        c.relations.insert(
            product_key.clone(),
            Relation {
                name: product_key.clone(),
                kind: RelKind::Many,
                collection: product.clone(),
                from: "id".into(),
                to: "id".into(),
                optional: false,
                through: Some((join, "target_id".into(), "owner_id".into())),
            },
        );
    }
}

/// A storefront checkout must be an editable native Page, with Woo's own block.
pub fn pages(schema: &Schema, span: Span) -> Result<()> {
    let (Some(store), Some(app)) = (&schema.store, &schema.app) else {
        return Ok(());
    };
    let checkout = store["checkout"].as_str().unwrap_or("/checkout").trim_end_matches('/');
    for route in &app.routes {
        let path = route.pattern.trim_end_matches('/');
        if route.prerender && (path == "/cart" || path == checkout || path.starts_with(&format!("{checkout}/"))) {
            return Err(Diag::new(
                span,
                "cart, checkout and receipt pages are private and cannot be prerendered",
            ));
        }
    }
    let Some(page) = app.content.values().find(|p| p.path.trim_end_matches('/') == checkout) else {
        return Err(Diag::new(
            span,
            format!("commerce checkout needs a page.md at {checkout}"),
        ));
    };
    if page.status() != "publish" || !has_checkout_block(&page.body) {
        return Err(Diag::new(
            span,
            "the checkout page must be public and contain Woo's native Checkout Block",
        ));
    }
    Ok(())
}

fn has_checkout_block(body: &str) -> bool {
    // CommonMark emits literal code separately from raw HTML, including in quoted fences.
    let mut html = String::new();
    for event in pulldown_cmark::Parser::new(body) {
        match event {
            pulldown_cmark::Event::Html(raw) | pulldown_cmark::Event::InlineHtml(raw) => html.push_str(&raw),
            _ => html.push('\n'),
        }
    }
    let comments = regex::Regex::new(r"(?s)<!--(.*?)-->").unwrap();
    let delimiter =
        regex::Regex::new(r"(?s)^\s+(/?)wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)\s+(?:\{.*\}\s+)?(/?)$").unwrap();
    // Woo 11.3 expands the legacy empty wrapper. Newer templates serialize checkout controls.
    let legacy = regex::Regex::new(
        r#"(?i)^<div class="(?:[a-z0-9_-]+ +)*wp-block-woocommerce-checkout(?: +[a-z0-9_-]+)*"></div>$"#,
    )
    .unwrap();
    let wrapper = regex::Regex::new(
        r#"(?i)<div\s+(?:[^<>]*?\s)?class\s*=\s*["'](?:[^"'<>]*\s)?wp-block-woocommerce-checkout(?:\s[^"'<>]*)?["']"#,
    )
    .unwrap();
    let action_tag =
        regex::Regex::new(r#"(?i)<div\s+[^<>]*?\bdata-block-name="woocommerce/checkout-actions-block"(?:\s|>)"#)
            .unwrap();
    let mut open = Vec::new();
    let mut found = false;
    for comment in comments.captures_iter(&html) {
        let Some(token) = delimiter.captures(comment.get(1).unwrap().as_str()) else {
            continue;
        };
        let name = token.get(2).unwrap().as_str();
        let span = comment.get(0).unwrap();
        if &token[1] == "/" {
            let Some((opened, start, has_action)) = open.pop() else {
                return false;
            };
            if opened != name {
                return false;
            }
            if name == "woocommerce/checkout" {
                let content = &html[start..span.start()];
                let markup = comments.replace_all(content, "");
                found |= legacy.is_match(content.trim())
                    || (wrapper.is_match(&markup) && (has_action || action_tag.is_match(&markup)));
            }
        } else {
            if name == "woocommerce/checkout-actions-block"
                && let Some((_, _, has_action)) = open
                    .iter_mut()
                    .rev()
                    .find(|(parent, _, _)| *parent == "woocommerce/checkout")
            {
                *has_action = true;
            }
            if token[3].is_empty() {
                open.push((name, span.end(), false));
            }
        }
    }
    found && open.is_empty()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn checkout_requires_a_native_block_outside_code() {
        let block = r#"<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->"#;
        for invalid in [
            "<!-- wp:woocommerce/checkout /-->",
            "<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-actions-block /--><!-- /wp:woocommerce/checkout -->",
            "<!-- wp:woocommerce/checkout -->   <!-- /wp:woocommerce/checkout -->",
            "<!-- wp:woocommerce/checkout --><div></div>",
            "<!-- wp:woocommerce/checkout --><div></div><!-- /wp:woocommerce/checkout -->",
            "<!-- wp:woocommerce/checkout --><!-- wp:paragraph /--><!-- /wp:woocommerce/checkout -->",
            &format!("<!-- /wp:group -->{block}"),
            &format!("<!-- wp:group -->{block}<!-- /wp:paragraph -->"),
        ] {
            assert!(!has_checkout_block(invalid), "{invalid}");
        }
        assert!(has_checkout_block(&format!(
            "<!-- wp:group -->{block}<!-- /wp:group -->"
        )));
        assert!(!has_checkout_block("<!-- wp:woocommerce/checkout broken -->"));
        assert!(!has_checkout_block(&format!("~~~html\n{block}\n~~~")));
        assert!(!has_checkout_block(&format!("> ```html\n> {block}\n> ```")));
        assert!(!has_checkout_block(&format!("`{block}`")));
    }

    #[test]
    fn shipping_amount_uses_ascii_digits() {
        assert!(money(&Json::from("15.00")));
        assert!(!money(&Json::from("١٥.٠٠")));
    }
}
