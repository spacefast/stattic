//! Flow-insensitive mutation effects, computed before return/capture inference.
//! Binding spans distinguish shadowed locals; call edges carry parameter paths
//! back to arguments without treating scalar parameter assignment as a write.

use super::*;
use indexmap::{IndexMap, IndexSet};

#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub(super) enum Step {
    Field(String),
    Index,
    /// Array mutators may remove every element, invalidating a lower bound.
    Resize,
    /// A raw JavaScript write can replace the value's entire static domain.
    DynamicValue,
    /// A bounded JavaScript write can replace any value below this prefix.
    DynamicDescendants,
    /// Recursive calls can reach descendants beyond the program's explicit paths.
    Descendants,
    Result,
}

#[derive(Clone, Debug, PartialEq, Eq, Hash)]
struct Place {
    binding: Span,
    path: Vec<Step>,
}

impl Place {
    fn new(binding: Span) -> Self {
        Self {
            binding,
            path: Vec::new(),
        }
    }
    fn at(&self, step: Step) -> Self {
        let mut place = self.clone();
        place.path.push(step);
        place
    }
}

// Debug builds count path projections, the step every solver rule repeats per pair of places,
// so a test can bound the solver's work by the size of its result (see `debug_dump`).
#[cfg(debug_assertions)]
thread_local! {
    static PROJECTIONS: std::cell::Cell<u64> = const { std::cell::Cell::new(0) };
}

/// An indexed read can select a named object property as well as an array
/// element. Keep the remainder so container operations preserve nested values.
fn projected_suffix<'a>(path: &'a [Step], prefix: &[Step]) -> Option<&'a [Step]> {
    #[cfg(debug_assertions)]
    PROJECTIONS.with(|count| count.set(count.get() + 1));
    for (i, step) in prefix.iter().enumerate() {
        if matches!(step, Step::Descendants) {
            return Some(&[]);
        }
        let actual = path.get(i)?;
        if actual == &Step::DynamicDescendants {
            return Some(&path[i..]);
        }
        if actual != step
            && !matches!(
                (actual, step),
                (Step::Field(_), Step::Index) | (Step::Index, Step::Field(_))
            )
        {
            return None;
        }
    }
    path.get(prefix.len()..)
}

fn callable_at(value: &Place, callee: &Place) -> bool {
    value.binding == callee.binding && projected_suffix(&value.path, &callee.path).is_some_and(<[Step]>::is_empty)
}

/// A place's number in the solver's table of places. Event sets and function summaries hold
/// numbers, because they repeat the same few thousand places millions of times. See
/// docs/decisions/2026-11-12-effects-event-scale.md.
type Id = u32;

/// An insertion-ordered set of place numbers: the order for the solver's read cursors, and one
/// bit per place number for membership.
#[derive(Clone, Default)]
struct IdSet {
    order: Vec<Id>,
    bits: Vec<u64>,
}

impl IdSet {
    fn insert(&mut self, id: Id) -> bool {
        let (word, bit) = (id as usize / 64, 1u64 << (id % 64));
        if word >= self.bits.len() {
            self.bits.resize(word + 1, 0);
        }
        if self.bits[word] & bit != 0 {
            return false;
        }
        self.bits[word] |= bit;
        self.order.push(id);
        true
    }
    fn len(&self) -> usize {
        self.order.len()
    }
    /// The numbers from position `from` up to `to`, in insertion order.
    fn unread(&self, from: usize, to: usize) -> &[Id] {
        self.order.get(from..to).unwrap_or_default()
    }
}

/// Numbers a place, adding it to the table the first time.
fn intern(places: &mut IndexSet<Place>, place: Place) -> Id {
    places.insert_full(place).0 as Id
}

#[derive(Default)]
pub(super) struct Effects {
    reassigned: IndexSet<Span>,
    captured: IndexSet<Span>,
    captured_data: IndexMap<(u8, usize), Place>,
    numeric: IndexMap<Span, IndexSet<Vec<Step>>>,
    incoming: IndexMap<Span, IndexSet<Vec<Step>>>,
    incoming_writes: IndexMap<Span, IndexSet<Vec<Step>>>,
    /// The places the sets below number.
    places: IndexSet<Place>,
    capture_writes: IndexMap<Span, Vec<Id>>,
    events: IndexMap<Span, Vec<Id>>,
    write_events: IndexMap<Span, Vec<Id>>,
    expression_writes: IndexMap<Span, Vec<Id>>,
    loop_writes: IndexMap<Span, Vec<Id>>,
    /// Every reachable write, by the binding it starts from.
    binding_writes: IndexMap<Span, IndexSet<Vec<Step>>>,
}

impl Effects {
    /// The places of one solved set, in the order the solver found them.
    fn places_in<'a>(
        &'a self,
        sets: &'a IndexMap<Span, Vec<Id>>,
        key: Span,
    ) -> Option<impl Iterator<Item = &'a Place>> {
        sets.get(&key)
            .map(|ids| ids.iter().map(|id| &self.places[*id as usize]))
    }

    /// Paths written below `binding` anywhere reachable. Calls carry a helper's writes back to
    /// the argument it was given, and copies carry them across aliases, so this covers writes
    /// through helpers, closures and other names for the same value. Every callee in compiled
    /// code is itself compiled, so an argument passed to a call has no unseen writer.
    pub fn writes_below(&self, binding: Span) -> Vec<Vec<Step>> {
        self.binding_writes
            .get(&binding)
            .into_iter()
            .flatten()
            .cloned()
            .collect()
    }

    pub fn binding_reassigned(&self, binding: Span) -> bool {
        self.reassigned.contains(&binding)
    }
    pub fn captured(&self, binding: Span) -> bool {
        self.captured.contains(&binding)
    }

    pub fn analyze(roots: &[Root], schema: &Schema, program: &crate::loader::Program) -> Self {
        let javascript_modules = program
            .modules
            .iter()
            .filter(|module| module.dynamic)
            .map(|module| module.id)
            .collect();
        let mut graph = Graph {
            javascript_modules,
            ..Graph::default()
        };
        for root in roots {
            graph.function(&root.closure.func, &[], &root.closure.env);
            graph.reachable.insert(root.closure.func.span);
            graph.roots.insert(root.closure.func.span);
            let f = root.closure.func.span;
            let params = graph.functions[&f].clone();
            match &root.slot {
                Slot::Validate { at } if params.len() > 1 => {
                    let mut field = params[1].at(Step::Field("data".into()));
                    let mut column_path = at.clone();
                    column_path.path.truncate(1);
                    field = field.at(Step::Field(at.path[0].clone()));
                    let mut rest = &at.path[1..];
                    while !rest.is_empty() {
                        let col = schema.column_at(&column_path);
                        let key = if col.ty == ColType::Blocks {
                            field = field.at(Step::Index);
                            column_path.path.push(rest[0].clone());
                            rest = &rest[1..];
                            &rest[0]
                        } else {
                            if col.ty == ColType::Repeater {
                                field = field.at(Step::Index);
                            }
                            &rest[0]
                        };
                        field = field.at(Step::Field(key.clone()));
                        column_path.path.push(key.clone());
                        rest = &rest[1..];
                    }
                    graph.copies.insert((field, params[0].clone(), f));
                }
                Slot::Hook {
                    kind: HookKind::BeforeCreate | HookKind::BeforeUpdate,
                    ..
                } if params.len() > 1 => {
                    let ctx = params.last().unwrap().at(Step::Field("data".into()));
                    graph.copies.insert((ctx, params[0].clone(), f));
                }
                _ => {}
            }
        }
        #[cfg(debug_assertions)]
        PROJECTIONS.with(|count| count.set(0));
        // A solve that finds a JavaScript-owned binding past the widening threshold stops; the next
        // solve starts again from the initial graph with that binding widened. See `WIDEN_ABOVE`.
        let mut widened = IndexSet::new();
        let effects = loop {
            let mut attempt = graph.clone();
            attempt.widened = widened.clone();
            match attempt.solve() {
                Ok(effects) => break effects,
                Err(more) => widened.extend(more),
            }
        };
        #[cfg(debug_assertions)]
        debug_dump(&effects, PROJECTIONS.with(std::cell::Cell::get), &widened);
        effects
    }

    pub fn captured_data_type(&self, value: &Value, ty: &Ty) -> Ty {
        let key = match value {
            Value::Array(a) => (0, Rc::as_ptr(a) as usize),
            Value::Object(o) => (1, Rc::as_ptr(o) as usize),
            _ => return ty.clone(),
        };
        self.captured_data
            .get(&key)
            .map_or_else(|| ty.clone(), |p| self.read_view_type(p.binding, &p.path, ty))
    }

    pub fn read_view_type(&self, binding: Span, view: &[Step], ty: &Ty) -> Ty {
        self.numeric.get(&binding).map_or_else(
            || ty.clone(),
            |paths| {
                paths
                    .iter()
                    .filter_map(|path| below_view(path, view))
                    .fold(ty.clone(), |ty, path| widen(&ty, path))
            },
        )
    }

    /// Keep authored parameter constraints separate from numeric values and
    /// tuple positions changed by the caller before invoking us.
    pub fn parameter_type(&self, binding: Span, ty: &Ty) -> Ty {
        let ty = self.incoming_writes.get(&binding).map_or_else(
            || ty.clone(),
            |paths| paths.iter().fold(ty.clone(), |ty, path| restore(&ty, &ty, path)),
        );
        self.incoming.get(&binding).map_or_else(
            || ty.clone(),
            |paths| paths.iter().fold(ty.clone(), |ty, path| widen(&ty, path)),
        )
    }

    /// A write invalidates refinements of that location and its descendants.
    /// Sibling refinements and the authored assignment constraint remain intact.
    pub fn invalidate(&self, event: Span, binding: Span, view: &[Step], ty: &Ty, constraint: &Ty) -> Ty {
        self.places_in(&self.write_events, event).map_or_else(
            || ty.clone(),
            |paths| invalidate_paths(paths, binding, view, ty, constraint),
        )
    }

    pub fn invalidate_loop(&self, event: Span, binding: Span, view: &[Step], ty: &Ty, constraint: &Ty) -> Ty {
        self.places_in(&self.loop_writes, event).map_or_else(
            || ty.clone(),
            |paths| invalidate_paths(paths, binding, view, ty, constraint),
        )
    }

    pub fn changes_refinement(&self, expression: Span, binding: Span, view: &[Step]) -> bool {
        self.places_in(&self.expression_writes, expression)
            .is_some_and(|mut writes| {
                writes.any(|p| {
                    p.binding == binding
                        && (projected_suffix(&p.path, view).is_some() || projected_suffix(view, &p.path).is_some())
                })
            })
    }

    pub fn capture_type(&self, function: Span, binding: Span, view: &[Step], ty: &Ty, constraint: &Ty) -> Ty {
        let ty = self.places_in(&self.capture_writes, function).map_or_else(
            || ty.clone(),
            |paths| invalidate_paths(paths, binding, view, ty, constraint),
        );
        self.read_view_type(binding, view, &ty)
    }

    pub fn write_type(&self, event: Span, binding: Span, view: &[Step], ty: &Ty) -> Ty {
        self.places_in(&self.events, event).map_or_else(
            || ty.clone(),
            |paths| {
                paths
                    .filter(|p| p.binding == binding)
                    .filter_map(|p| below_view(&p.path, view))
                    .fold(ty.clone(), |ty, path| widen(&ty, path))
            },
        )
    }
}

/// What a place changes below a read view: its steps after the view when it lies under it, or
/// its `Descendants`/`DynamicDescendants` tail when it was cut above the end of the view and so
/// covers all of it, as `projected_suffix` treats that step. Steps before the cut must equal
/// the view's.
fn below_view<'a>(path: &'a [Step], view: &[Step]) -> Option<&'a [Step]> {
    if let Some(rest) = path.strip_prefix(view) {
        return Some(rest);
    }
    let cut = path
        .iter()
        .position(|step| matches!(step, Step::Descendants | Step::DynamicDescendants))?;
    (cut < view.len() && path[..cut] == view[..cut]).then(|| &path[cut..])
}

fn invalidate_paths<'a>(
    paths: impl Iterator<Item = &'a Place>,
    binding: Span,
    view: &[Step],
    ty: &Ty,
    constraint: &Ty,
) -> Ty {
    paths.filter(|p| p.binding == binding).fold(ty.clone(), |ty, p| {
        if let Some(path) = projected_suffix(&p.path, view) {
            restore(&ty, constraint, path)
        } else if projected_suffix(view, &p.path).is_some() {
            restore_constraint(&ty, constraint)
        } else {
            ty
        }
    })
}

// Dropping a narrowing must not undo a tuple/number view widened by a prior mutation.
fn restore_constraint(ty: &Ty, constraint: &Ty) -> Ty {
    match (ty, constraint) {
        (Ty::Dynamic, _) => Ty::Dynamic,
        (Ty::Instance(id, fields), Ty::Instance(_, original)) => {
            Ty::Instance(id.clone(), Box::new(restore_constraint(fields, original)))
        }
        (Ty::Num, t) if t.is_num() => Ty::Num,
        (Ty::Array(t), Ty::NonEmptyArray(c)) => Ty::Array(Box::new(restore_constraint(t, c))),
        (Ty::NonEmptyArray(t), Ty::NonEmptyArray(c)) => Ty::NonEmptyArray(Box::new(restore_constraint(t, c))),
        (Ty::Array(t), Ty::Tuple(ts) | Ty::ArrayLiteral(ts)) => Ty::Array(Box::new(Ty::union(
            std::iter::once((**t).clone()).chain(ts.iter().cloned()),
        ))),
        (Ty::Array(t), Ty::Array(c)) => Ty::Array(Box::new(restore_constraint(t, c))),
        (Ty::Obj(fields), Ty::Obj(original)) => Ty::Obj(
            original
                .iter()
                .map(|(k, (c, opt))| {
                    (
                        k.clone(),
                        (
                            fields
                                .get(k)
                                .map_or_else(|| c.clone(), |(t, _)| restore_constraint(t, c)),
                            *opt,
                        ),
                    )
                })
                .collect(),
        ),
        _ => constraint.clone(),
    }
}

fn restore(ty: &Ty, constraint: &Ty, path: &[Step]) -> Ty {
    if let (Ty::Instance(id, fields), Ty::Instance(_, original)) = (ty, constraint) {
        return Ty::Instance(id.clone(), Box::new(restore(fields, original, path)));
    }
    let Some((step, rest)) = path.split_first() else {
        return restore_constraint(ty, constraint);
    };
    match (ty, step) {
        (_, Step::DynamicValue | Step::DynamicDescendants) => Ty::Dynamic,
        (Ty::NonEmptyArray(t), Step::Resize | Step::Descendants) => Ty::Array(t.clone()),
        (Ty::NonEmptyArray(t), Step::Index) => {
            let original = match constraint {
                Ty::NonEmptyArray(c) | Ty::Array(c) => c.as_ref(),
                _ => t.as_ref(),
            };
            Ty::NonEmptyArray(Box::new(restore(t, original, rest)))
        }
        (Ty::Union(ts), _) => Ty::union(ts.iter().map(|t| restore(t, constraint, path))),
        (_, Step::Descendants) => {
            // A member write can replace descendants, never the receiver binding: a receiver
            // narrowed away from null, or from an "or false" result's `false`, stays narrowed.
            let constraint = if ty.is_nullable() {
                constraint.clone()
            } else {
                constraint.non_null()
            };
            let constraint = match ty {
                Ty::Union(items) if items.iter().any(|item| item.bool_literal() == Some(false)) => constraint,
                _ if ty.is_bool() => constraint,
                _ => constraint.without_bool(false),
            };
            restore_constraint(ty, &constraint)
        }
        (Ty::Obj(fields), Step::Field(field)) => {
            let mut fields = fields.clone();
            if let Some((ty, _)) = fields.get_mut(field)
                && let Some((original, _)) = constraint.non_null().field(field)
            {
                *ty = restore(ty, &original, rest);
            }
            Ty::Obj(fields)
        }
        (Ty::Obj(fields), Step::Index) => Ty::Obj(
            fields
                .iter()
                .map(|(key, (ty, optional))| {
                    let original = constraint
                        .non_null()
                        .field(key)
                        .map(|(t, _)| t)
                        .unwrap_or_else(|| ty.clone());
                    (key.clone(), (restore(ty, &original, rest), *optional))
                })
                .collect(),
        ),
        (Ty::Array(t), Step::Index) => match constraint.non_null() {
            Ty::Array(original) => Ty::Array(Box::new(restore(t, &original, rest))),
            _ => ty.clone(),
        },
        (Ty::Map(t), _) => match constraint.non_null() {
            Ty::Map(original) => Ty::Map(Box::new(restore(t, &original, rest))),
            _ => ty.clone(),
        },
        (Ty::Tuple(ts), Step::Index) | (Ty::ArrayLiteral(ts), Step::Index) => {
            let original = match constraint.non_null() {
                Ty::Tuple(ts) | Ty::ArrayLiteral(ts) => ts,
                _ => return ty.clone(),
            };
            if rest.is_empty() {
                // Mutators can reorder, replace, grow or shrink a tuple. Its
                // read view becomes an array; authored constraints stay fixed.
                return Ty::Array(Box::new(Ty::union(ts.iter().chain(&original).cloned())));
            }
            let values = ts.iter().zip(&original).map(|(t, c)| restore(t, c, rest)).collect();
            if matches!(ty, Ty::Tuple(_)) {
                Ty::Tuple(values)
            } else {
                Ty::ArrayLiteral(values)
            }
        }
        _ => ty.clone(),
    }
}

fn widen(ty: &Ty, path: &[Step]) -> Ty {
    if let Ty::Instance(id, fields) = ty {
        return Ty::Instance(id.clone(), Box::new(widen(fields, path)));
    }
    let Some((step, rest)) = path.split_first() else {
        return match ty {
            Ty::Num | Ty::NumLit(_) | Ty::FreshNumLit(_) => Ty::Num,
            Ty::Union(ts) => Ty::union(ts.iter().map(|t| widen(t, path))),
            _ => ty.clone(),
        };
    };
    match (ty, step) {
        (_, Step::DynamicValue | Step::DynamicDescendants) => Ty::Dynamic,
        (Ty::NonEmptyArray(t), Step::Resize | Step::Descendants) => Ty::Array(Box::new(widen(t, rest))),
        (Ty::NonEmptyArray(t), Step::Index) => Ty::NonEmptyArray(Box::new(widen(t, rest))),
        (Ty::Union(ts), _) => Ty::union(ts.iter().map(|t| widen(t, path))),
        (_, Step::Descendants) => match ty {
            Ty::Obj(fields) => Ty::Obj(
                fields
                    .iter()
                    .map(|(k, (t, opt))| (k.clone(), (widen(t, path), *opt)))
                    .collect(),
            ),
            Ty::Array(t) => Ty::Array(Box::new(widen(t, path))),
            Ty::Map(t) => Ty::Map(Box::new(widen(t, path))),
            Ty::Tuple(ts) => Ty::Tuple(ts.iter().map(|t| widen(t, path)).collect()),
            Ty::ArrayLiteral(ts) => Ty::ArrayLiteral(ts.iter().map(|t| widen(t, path)).collect()),
            _ => widen(ty, &[]),
        },
        (Ty::Obj(fields), Step::Field(field)) => {
            let mut fields = fields.clone();
            if let Some((ty, _)) = fields.get_mut(field) {
                *ty = widen(ty, rest);
            }
            Ty::Obj(fields)
        }
        (Ty::Obj(fields), Step::Index) => Ty::Obj(
            fields
                .iter()
                .map(|(k, (t, opt))| (k.clone(), (widen(t, rest), *opt)))
                .collect(),
        ),
        (Ty::Array(t), Step::Index) => Ty::Array(Box::new(widen(t, rest))),
        (Ty::Tuple(ts), Step::Index) => Ty::Tuple(ts.iter().map(|t| widen(t, rest)).collect()),
        (Ty::ArrayLiteral(ts), Step::Index) => Ty::ArrayLiteral(ts.iter().map(|t| widen(t, rest)).collect()),
        (Ty::Map(t), _) => Ty::Map(Box::new(widen(t, rest))),
        _ => ty.clone(),
    }
}

pub(super) fn class_static_read(ty: &Ty) -> Ty {
    widen(ty, &[Step::Descendants])
}

type Places = IndexSet<Place>;

/// Cuts a derived place at the solver's depth bound, keeping whether a dynamic write reached it.
fn bounded(mut p: Place, max_depth: usize) -> Place {
    if p.path.len() > max_depth {
        let terminal = if p
            .path
            .iter()
            .any(|step| matches!(step, Step::DynamicValue | Step::DynamicDescendants))
        {
            Step::DynamicDescendants
        } else {
            Step::Descendants
        };
        p.path.truncate(max_depth);
        p.path.push(terminal);
    }
    p
}

/// A group is the places of one set with two or more steps below one binding. When a group of a
/// JavaScript-owned binding grows past this, the binding is widened: its places keep their first
/// step and end in `DynamicDescendants`, which covers every place they replace. The largest such
/// group in the repository's programs is 42 (`tests/differential/cases.ts`); picomatch's parser
/// reaches thousands. See docs/decisions/2026-11-08-effects-widening.md.
const WIDEN_ABOVE: usize = 64;

/// The end-of-pass check sees a group only after a whole pass. Alias expansion within one pass
/// can grow a group far past `WIDEN_ABOVE` first (360K places in one files-sdk event set), so it
/// widens a JavaScript-owned binding as soon as one of its groups in the set being expanded
/// passes this bound. It is far above `WIDEN_ABOVE` so it only cuts a pass that would run away;
/// every program that finished before reaches the same widened bindings as before. See
/// docs/decisions/2026-11-12-effects-event-scale.md.
const RUNAWAY_ABOVE: usize = 1024;

/// Coarsens a place of a widened binding to its first step and everything below it.
fn coarsened(mut p: Place, widened: &IndexSet<Span>) -> Place {
    if p.path.len() > 1 && widened.contains(&p.binding) {
        p.path.truncate(1);
        p.path.push(Step::DynamicDescendants);
    }
    p
}

/// A place a solver rule derives into an effect set: cut at the depth bound, then coarsened.
fn derived(p: Place, max_depth: usize, widened: &IndexSet<Span>) -> Place {
    coarsened(bounded(p, max_depth), widened)
}

/// The elements of an insertion-ordered set from position `from` up to `to`.
fn unread<T>(set: &IndexSet<T>, from: usize, to: usize) -> impl Iterator<Item = &T> {
    set.get_range(from..to).into_iter().flat_map(|slice| slice.iter())
}

/// How far each solver rule has read the growing sets, so a pass only reads new facts.
#[derive(Default)]
struct Solved {
    /// Call edges whose argument, `arguments` and result copies exist.
    linked: IndexSet<(usize, Span)>,
    viewed_incoming: usize,
    viewed_incoming_writes: usize,
    numeric_from: IndexMap<Span, usize>,
    writes_from: IndexMap<Span, usize>,
    numeric_incoming: usize,
    /// Escaped places already turned into `Descendants` writes at unknown calls.
    invalidated: usize,
    /// Write events that already received their owner's external `Descendants` places.
    external_added: IndexSet<Span>,
    /// Places and escapes a write event was checked against without changing an external value.
    external_checked: IndexMap<Span, (usize, usize)>,
    /// Reachable copies already in `aliases`, by position in `copies`.
    aliased: IndexSet<usize>,
    aliases: IndexMap<Span, Vec<(Place, Place)>>,
    /// Places of each numbered set already closed under aliases, by `EffectSet` and key.
    expanded: [IndexMap<Span, usize>; 4],
    edges: IndexMap<(usize, Span), EdgeRead>,
    /// `events` and `write_events`, each event's places by binding.
    event_bindings: [IndexMap<Span, ByBinding>; 2],
    /// Each place's alias targets, for the aliases of the current pass, by place number.
    alias_targets: Vec<Option<Rc<[Id]>>>,
    /// Group sizes of each numbered set (`events`, `write_events`, `function_numeric`,
    /// `function_writes`, by position and key) and of `numeric`, `writes`, `incoming` and
    /// `incoming_writes`.
    groups: IndexMap<(usize, Span), Groups>,
    plain_groups: [Groups; 4],
}

/// The size of each group in one set, counted up to position `read`.
#[derive(Default)]
struct Groups {
    read: usize,
    sizes: IndexMap<Span, usize>,
}

/// Positions in an insertion-ordered set of place numbers, by binding.
#[derive(Default)]
struct ByBinding {
    indexed: usize,
    positions: IndexMap<Span, Vec<usize>>,
}

impl ByBinding {
    fn extend(&mut self, set: &IdSet, places: &IndexSet<Place>) {
        for (position, id) in set.unread(self.indexed, set.len()).iter().enumerate() {
            self.positions
                .entry(places[*id as usize].binding)
                .or_default()
                .push(self.indexed + position);
        }
        self.indexed = set.len();
    }

    /// Positions of `binding`'s places from `from` on, in insertion order.
    fn unread(&self, binding: Span, from: usize) -> impl Iterator<Item = usize> + '_ {
        let positions = self.positions.get(&binding).map_or(&[][..], Vec::as_slice);
        positions[positions.partition_point(|&position| position < from)..]
            .iter()
            .copied()
    }
}

/// What one call edge has read from each input of its views and summaries.
#[derive(Default)]
struct EdgeRead {
    incoming: usize,
    incoming_writes: usize,
    numeric: usize,
    writes: usize,
    events: IndexMap<Span, usize>,
    write_events: IndexMap<Span, usize>,
    function_numeric: usize,
    function_writes: usize,
}

#[derive(Clone)]
struct Call {
    receiver: Option<Place>,
    callee: Place,
    args: Vec<Option<Place>>,
    spreads: IndexSet<usize>,
    result: Place,
    owner: Span,
    span: Span,
    prior: IndexSet<Span>,
}

impl Call {
    fn arguments(&self, method: bool) -> Vec<(Option<Place>, bool)> {
        let args = self
            .args
            .iter()
            .enumerate()
            .map(|(index, value)| (value.clone(), self.spreads.contains(&index)));
        if method {
            std::iter::once((self.receiver.clone(), false)).chain(args).collect()
        } else {
            args.collect()
        }
    }
}

#[derive(Clone, Default)]
struct Graph {
    javascript_modules: IndexSet<usize>,
    javascript_functions: IndexSet<Span>,
    scalar_functions: IndexSet<Span>,
    classes: IndexSet<usize>,
    methods: IndexSet<Span>,
    captured: IndexSet<Span>,
    roots: IndexSet<Span>,
    reachable: IndexSet<Span>,
    incoming: Places,
    incoming_writes: Places,
    history: IndexMap<Span, IndexSet<Span>>,
    created_prior: IndexMap<Span, IndexSet<Span>>,
    created_by: IndexMap<Span, Span>,
    expression_events: IndexMap<Span, IndexSet<Span>>,
    loop_events: IndexMap<Span, IndexSet<Span>>,
    functions: IndexMap<Span, Vec<Place>>,
    argument_values: IndexMap<Span, Place>,
    argument_reads: IndexSet<Span>,
    rest_parameters: IndexMap<Span, usize>,
    values: IndexSet<(Place, Span)>,
    slice_methods: Places,
    borrowed_slices: Vec<(Place, Place, Place, Span)>,
    copies: IndexSet<(Place, Place, Span)>,
    captured_data: IndexMap<(u8, usize), Place>,
    external: IndexSet<Span>,
    escaped: Places,
    call_events: IndexMap<Span, Span>,
    event_owners: IndexMap<Span, Span>,
    calls: Vec<Call>,
    numeric: Places,
    writes: Places,
    max_depth: usize,
    bindings: IndexMap<Span, Span>,
    parameter_views: IndexMap<Span, Place>,
    /// The places that `function_numeric`, `function_writes`, `events` and `write_events` number.
    places: IndexSet<Place>,
    function_numeric: IndexMap<Span, IdSet>,
    function_writes: IndexMap<Span, IdSet>,
    events: IndexMap<Span, IdSet>,
    write_events: IndexMap<Span, IdSet>,
    /// JavaScript-owned bindings whose effect places are coarsened. Fixed for one solve, except
    /// that alias expansion adds a binding whose group runs away within a pass (`RUNAWAY_ABOVE`);
    /// that solve then ends with the binding among those to widen.
    widened: IndexSet<Span>,
}

/// What a callee's summary place means to a caller.
enum CallerEffects {
    /// The place the callee wrote is visible to the caller as it is.
    Same,
    /// The caller's places for it: arguments with the parameter's suffix, or none for the callee's locals.
    Mapped(Vec<Place>),
}

#[derive(Clone, Copy)]
enum EffectSet {
    Events,
    WriteEvents,
    FunctionNumeric,
    FunctionWrites,
}

type Names = Vec<IndexMap<String, Place>>;

impl Graph {
    /// Adds a place to one of the numbered effect sets.
    fn record(&mut self, set: EffectSet, key: Span, place: Place) {
        let id = intern(&mut self.places, place);
        let sets = match set {
            EffectSet::Events => &mut self.events,
            EffectSet::WriteEvents => &mut self.write_events,
            EffectSet::FunctionNumeric => &mut self.function_numeric,
            EffectSet::FunctionWrites => &mut self.function_writes,
        };
        sets.entry(key).or_default().insert(id);
    }

    fn function(&mut self, f: &Rc<Func>, outer: &[IndexMap<String, Place>], env: &Rc<Scope>) -> Place {
        let owner = f.span;
        if self.javascript_modules.contains(&f.module) {
            self.javascript_functions.insert(owner);
        }
        if f.ret.as_ref().is_some_and(scalar_return_annotation) {
            self.scalar_functions.insert(owner);
        }
        let value = Place::new(f.span);
        self.values.insert((value.clone(), f.span));
        if self.functions.contains_key(&f.span) {
            return value;
        }
        self.functions.insert(f.span, Vec::new());
        if let Some(index) = f.params.iter().position(|parameter| parameter.rest) {
            self.rest_parameters.insert(f.span, index);
        }
        let mut names = outer.to_vec();
        names.push(IndexMap::new());
        if let Some(name) = &f.name {
            names.last_mut().unwrap().insert(name.clone(), value.clone());
        }
        let params: Vec<Place> = f
            .params
            .iter()
            .map(|p| {
                self.bind(&p.pat, &mut names, f.span);
                let param = Place::new(p.pat.span());
                self.bindings.insert(param.binding, owner);
                self.external.insert(param.binding);
                self.bind_value(&p.pat, Some(param.clone()), owner);
                self.parameter_pattern(&p.pat, &param);
                param
            })
            .collect();
        if !f.is_arrow && !names.last().expect("function scope").contains_key("arguments") {
            let binding = javascript::arguments_binding(f);
            let arguments = Place::new(binding);
            names
                .last_mut()
                .expect("function scope")
                .insert("arguments".into(), arguments.clone());
            self.bindings.insert(binding, owner);
            self.argument_values.insert(owner, arguments.clone());
        }
        self.functions.insert(f.span, params);
        if let FnBody::Block(body) = &f.body {
            let mut vars = Vec::new();
            javascript::collect_var_declarations(body, &mut vars);
            for javascript::VarBinding { name, span, .. } in vars {
                if !names.last().expect("function scope").contains_key(name) {
                    names
                        .last_mut()
                        .expect("function scope")
                        .insert(name.to_string(), Place::new(span));
                    self.bindings.insert(span, owner);
                }
            }
        }
        for p in &f.params {
            self.defaults(&p.pat, &mut names, env, f.span);
            if let Some(e) = &p.default {
                let default = self.expr(e, &mut names, env, f.span);
                self.bind_value(&p.pat, default, owner);
            }
        }
        match &f.body {
            FnBody::Expr(e) => {
                let result = self.expr(e, &mut names, env, f.span);
                if !self.scalar_functions.contains(&owner) {
                    self.copy(result, Some(value.at(Step::Result)), owner);
                }
            }
            FnBody::Block(body) => self.block(body, &mut names, env, f.span),
        }
        value
    }

    fn bind(&mut self, pat: &Pat, names: &mut Names, owner: Span) {
        let mut bindings = Vec::new();
        fn collect(p: &Pat, out: &mut Vec<(String, Span)>) {
            match p {
                Pat::Id(name, span) => out.push((name.clone(), *span)),
                Pat::Default(p, _) => collect(p, out),
                Pat::Obj { props, rest, .. } => {
                    for (_, p) in props {
                        collect(p, out);
                    }
                    if let Some(p) = rest {
                        out.push(p.clone());
                    }
                }
                Pat::Arr { elems, rest, .. } => {
                    for p in elems.iter().flatten() {
                        collect(p, out);
                    }
                    if let Some(p) = rest {
                        out.push(p.clone());
                    }
                }
            }
        }
        collect(pat, &mut bindings);
        for (name, span) in bindings {
            names.last_mut().unwrap().insert(name, Place::new(span));
            self.bindings.insert(span, owner);
        }
    }

    fn defaults(&mut self, pat: &Pat, names: &mut Names, env: &Rc<Scope>, owner: Span) {
        match pat {
            Pat::Default(p, e) => {
                let value = self.expr(e, names, env, owner);
                self.bind_value(p, value, owner);
                self.defaults(p, names, env, owner);
            }
            Pat::Obj { props, .. } => {
                for (_, p) in props {
                    self.defaults(p, names, env, owner);
                }
            }
            Pat::Arr { elems, .. } => {
                for p in elems.iter().flatten() {
                    self.defaults(p, names, env, owner);
                }
            }
            Pat::Id(..) => {}
        }
    }

    fn assign_pattern(
        &mut self,
        pattern: &AssignPat,
        src: Option<Place>,
        names: &mut Names,
        env: &Rc<Scope>,
        owner: Span,
        event: Span,
    ) {
        match pattern {
            AssignPat::Target(target) => {
                if let Some(p) = self.expr(target, names, env, owner) {
                    self.record(EffectSet::WriteEvents, event, p.clone());
                    self.record(EffectSet::FunctionWrites, owner, p.clone());
                    self.record(EffectSet::Events, event, p.clone());
                    // As for `=`: a JavaScript function may store any value into a member.
                    if self.javascript_functions.contains(&owner) && !p.path.is_empty() {
                        let dynamic = p.at(Step::DynamicValue);
                        self.record(EffectSet::WriteEvents, event, dynamic.clone());
                        self.record(EffectSet::FunctionWrites, owner, dynamic.clone());
                        self.record(EffectSet::Events, event, dynamic.clone());
                        self.record(EffectSet::FunctionNumeric, owner, dynamic);
                    }
                    self.copy(src, Some(p), owner);
                }
            }
            AssignPat::Default(inner, default) => {
                let d = self.expr(default, names, env, owner);
                self.assign_pattern(inner, src, names, env, owner, event);
                if let Some(d) = d {
                    for target in inner.targets() {
                        if let Some(p) = self.expr(target, names, env, owner) {
                            self.copy(Some(d.clone()), Some(p), owner);
                        }
                    }
                }
            }
            AssignPat::Obj { props, rest, .. } => {
                for (key, p) in props {
                    let step = match key {
                        PropKey::Static(k) => Some(Step::Field(k.clone())),
                        PropKey::Computed(k) => {
                            self.expr(k, names, env, owner);
                            match &k.kind {
                                ExprKind::Str(k) => Some(Step::Field(k.clone())),
                                // A computed key reads an unknown field: the value is a copy of
                                // some part of the source, which the target sees as its own.
                                _ => None,
                            }
                        }
                    };
                    let part = step.and_then(|step| src.as_ref().map(|s| s.at(step)));
                    self.assign_pattern(p, part, names, env, owner, event);
                }
                if let Some(r) = rest {
                    self.assign_pattern(r, src.clone(), names, env, owner, event);
                }
            }
            AssignPat::Arr { elems, rest, .. } => {
                for p in elems.iter().flatten() {
                    self.assign_pattern(p, src.as_ref().map(|s| s.at(Step::Index)), names, env, owner, event);
                }
                if let Some(r) = rest {
                    self.assign_pattern(r, src.clone(), names, env, owner, event);
                }
            }
        }
    }

    fn bind_value(&mut self, pat: &Pat, src: Option<Place>, owner: Span) {
        match pat {
            Pat::Id(_, span) => self.copy(src, Some(Place::new(*span)), owner),
            Pat::Default(p, _) => self.bind_value(p, src, owner),
            Pat::Obj { props, rest, .. } => {
                for (key, p) in props {
                    self.bind_value(p, src.as_ref().map(|p| p.at(Step::Field(key.clone()))), owner);
                }
                if let Some((_, span)) = rest {
                    self.copy(src, Some(Place::new(*span)), owner);
                }
            }
            Pat::Arr { elems, rest, .. } => {
                for p in elems.iter().flatten() {
                    self.bind_value(p, src.as_ref().map(|p| p.at(Step::Index)), owner);
                }
                if let Some((_, span)) = rest {
                    self.copy(src, Some(Place::new(*span)), owner);
                }
            }
        }
    }

    fn parameter_pattern(&mut self, pat: &Pat, src: &Place) {
        match pat {
            Pat::Id(_, span) => {
                if *span != src.binding {
                    self.parameter_views.insert(*span, src.clone());
                }
            }
            Pat::Default(p, _) => self.parameter_pattern(p, src),
            Pat::Obj { props, rest, .. } => {
                for (key, p) in props {
                    self.parameter_pattern(p, &src.at(Step::Field(key.clone())));
                }
                if let Some((_, span)) = rest {
                    self.parameter_views.insert(*span, src.clone());
                }
            }
            Pat::Arr { elems, rest, .. } => {
                for p in elems.iter().flatten() {
                    self.parameter_pattern(p, &src.at(Step::Index));
                }
                if let Some((_, span)) = rest {
                    self.parameter_views.insert(*span, src.clone());
                }
            }
        }
    }

    fn copy(&mut self, src: Option<Place>, dest: Option<Place>, owner: Span) {
        if let (Some(src), Some(dest)) = (src, dest) {
            if dest.path.contains(&Step::Result) || !dest.path.is_empty() && self.external.contains(&dest.binding) {
                self.escaped.insert(src.clone());
            }
            self.copies.insert((src, dest, owner));
        }
    }

    fn data_place(&mut self, value: &Value) -> Option<Place> {
        let identity = match value {
            Value::Array(a) => (0, Rc::as_ptr(a) as usize),
            Value::Object(o) => (1, Rc::as_ptr(o) as usize),
            _ => return None,
        };
        if let Some(place) = self.captured_data.get(&identity) {
            return Some(place.clone());
        }
        let next = self.captured_data.len() as u32 + 1;
        let place = Place::new(Span {
            file: crate::diag::NO_FILE,
            start: next,
            end: next,
        });
        self.captured_data.insert(identity, place.clone());
        self.external.insert(place.binding);
        self.reachable.insert(Span::NONE);
        let children: Vec<_> = match value {
            Value::Array(a) => a.borrow().iter().map(|v| (Step::Index, v.clone())).collect(),
            Value::Object(o) => o
                .borrow()
                .iter()
                .map(|(k, v)| (Step::Field(k.clone()), v.clone()))
                .collect(),
            _ => unreachable!(),
        };
        for (step, value) in children {
            if let Some(child) = self.data_place(&value) {
                self.copies.insert((child, place.at(step), Span::NONE));
            }
        }
        Some(place)
    }

    fn value_place(&mut self, value: &Value) -> Option<Place> {
        match value {
            Value::Class(c) => Some(self.class_place(c)),
            Value::Closure(c) => Some(self.function(&c.func, &[], &c.env)),
            Value::Array(_) | Value::Object(_) => self.data_place(value),
            _ => None,
        }
    }

    /// The build-time value a static member chain (`ns.a.b`) reads, as the compiler resolves it:
    /// the root is a captured binding and every step is an object holding code or a function
    /// with own properties. Such namespaces never exist in PHP, so their members are constant.
    fn static_member(&self, e: &Expr, names: &Names, env: &Rc<Scope>) -> Option<Value> {
        fn walk(e: &Expr, names: &Names, env: &Rc<Scope>) -> Option<Value> {
            match &e.kind {
                ExprKind::Ident(name) if names.iter().all(|s| !s.contains_key(name)) => {
                    env.lookup(name).map(|b| b.value)
                }
                ExprKind::Member { obj, prop, .. } => {
                    let receiver = walk(obj, names, env)?;
                    if !super::is_static_namespace(&receiver) {
                        return None;
                    }
                    match &receiver {
                        Value::Object(o) => o.borrow().get(prop).cloned(),
                        Value::Closure(c) => c.props.borrow().get(prop).cloned(),
                        _ => None,
                    }
                }
                _ => None,
            }
        }
        matches!(e.kind, ExprKind::Member { .. })
            .then(|| walk(e, names, env))
            .flatten()
    }

    fn ident(&mut self, name: &str, names: &Names, env: &Rc<Scope>) -> Option<Place> {
        if let Some(p) = names.iter().rev().find_map(|s| s.get(name)) {
            if name == "arguments" {
                self.argument_reads.insert(p.binding);
            }
            return Some(p.clone());
        }
        self.value_place(&env.lookup(name)?.value)
    }

    fn class_place(&mut self, class: &Rc<ClassValue>) -> Place {
        let place = Place::new(class.class.span);
        if !self.classes.insert(Rc::as_ptr(class) as usize) {
            return place;
        }
        self.external.insert(place.binding);
        self.reachable.insert(Span::NONE);
        for (name, value) in &class.statics {
            let data = self.data_place(value);
            self.copy(data, Some(place.at(Step::Field(name.clone()))), Span::NONE);
        }
        if let Some(base) = &class.base {
            let parent = self.class_place(base);
            self.copy(Some(parent), Some(place.clone()), Span::NONE);
        }
        let env = Scope::new(Some(class.env.clone()), class.env.module);
        env.vars.borrow_mut().insert(
            ":class".into(),
            Binding {
                initialized: true,
                value: Value::Class(class.clone()),
                mutable: false,
                module_level: false,
                ann: None,
            },
        );
        for (name, is_static, f) in &class.class.methods {
            self.methods.insert(f.span);
            let method_env = Scope::new(Some(env.clone()), env.module);
            method_env.vars.borrow_mut().insert(
                ":static".into(),
                Binding {
                    initialized: true,
                    value: Value::Bool(*is_static),
                    mutable: false,
                    module_level: false,
                    ann: None,
                },
            );
            let function = self.function(f, &[], &method_env);
            let receiver = if *is_static {
                place.clone()
            } else {
                place.at(Step::Field(":prototype".into()))
            };
            self.copy(Some(function), Some(receiver.at(Step::Field(name.clone()))), Span::NONE);
        }
        // Symbol-keyed methods are reached only through checked JavaScript dispatch
        // (`obj[symbol](...)`), so their place holds the function under a name no source can spell.
        for (index, method) in class.class.symbol_methods.iter().enumerate() {
            self.methods.insert(method.func.span);
            let method_env = Scope::new(Some(env.clone()), env.module);
            method_env.vars.borrow_mut().insert(
                ":static".into(),
                Binding {
                    initialized: true,
                    value: Value::Bool(method.is_static),
                    mutable: false,
                    module_level: false,
                    ann: None,
                },
            );
            let function = self.function(&method.func, &[], &method_env);
            let receiver = if method.is_static {
                place.clone()
            } else {
                place.at(Step::Field(":prototype".into()))
            };
            self.copy(
                Some(function),
                Some(receiver.at(Step::Field(format!(":symbol{index}")))),
                Span::NONE,
            );
        }
        if let Some(f) = &class.class.constructor {
            self.methods.insert(f.span);
            let function = self.function(f, &[], &env);
            self.copy(
                Some(function),
                Some(place.at(Step::Field(":constructor".into()))),
                Span::NONE,
            );
        }
        for field in class.class.fields.iter().filter(|f| !f.is_static) {
            if let Some(f) = field.initializer(class.env.module) {
                self.methods.insert(f.span);
                let function = self.function(&f, &[], &env);
                self.copy(
                    Some(function),
                    Some(
                        place
                            .at(Step::Field(":fields".into()))
                            .at(Step::Field(field.name.clone())),
                    ),
                    Span::NONE,
                );
            }
        }
        place
    }

    fn expr(&mut self, e: &Expr, names: &mut Names, env: &Rc<Scope>, owner: Span) -> Option<Place> {
        let before = self.history.get(&owner).cloned().unwrap_or_default();
        let result = self.expr_inner(e, names, env, owner);
        let events = self
            .history
            .get(&owner)
            .into_iter()
            .flat_map(|events| events.difference(&before).copied())
            .collect();
        self.expression_events.insert(e.span, events);
        result
    }

    fn expr_inner(&mut self, e: &Expr, names: &mut Names, env: &Rc<Scope>, owner: Span) -> Option<Place> {
        let value = Place::new(e.span);
        self.bindings.entry(value.binding).or_insert(owner);
        if matches!(
            e.kind,
            ExprKind::Update { .. } | ExprKind::Assign { .. } | ExprKind::Call { .. } | ExprKind::New { .. }
        ) {
            self.event_owners.insert(e.span, owner);
        }
        match &e.kind {
            ExprKind::Jsx(jsx) => {
                for x in jsx.exprs() {
                    self.expr(x, names, env, owner);
                }
                return None;
            }
            ExprKind::Sequence(items) => {
                let mut last = None;
                for item in items {
                    last = self.expr(item, names, env, owner);
                }
                return last;
            }
            ExprKind::Ident(name) => {
                let place = self.ident(name, names, env)?;
                if self
                    .bindings
                    .get(&place.binding)
                    .is_some_and(|binding_owner| *binding_owner != owner)
                {
                    self.captured.insert(place.binding);
                    self.escaped.insert(place.clone());
                }
                return Some(place);
            }
            ExprKind::Func(f) => {
                self.created_by.insert(f.span, owner);
                self.created_prior
                    .entry(f.span)
                    .or_insert_with(|| self.history.get(&owner).cloned().unwrap_or_default());
                return Some(self.function(f, names, env));
            }
            ExprKind::Member { obj, prop, .. } => {
                if let Some(value) = self.static_member(e, names, env) {
                    return self.value_place(&value);
                }
                if matches!(&obj.kind, ExprKind::Ident(n) if n == "super")
                    && let Some(Value::Class(class)) = env.lookup(":class").map(|b| b.value)
                    && let Some(base) = &class.base
                {
                    let base = self.class_place(base);
                    let is_static = matches!(env.lookup(":static").map(|b| b.value), Some(Value::Bool(true)));
                    return Some(
                        if is_static {
                            base
                        } else {
                            base.at(Step::Field(":prototype".into()))
                        }
                        .at(Step::Field(prop.clone())),
                    );
                }
                let member = self
                    .expr(obj, names, env, owner)
                    .map(|p| p.at(Step::Field(prop.clone())));
                if prop == "slice"
                    && let Some(member) = &member
                {
                    self.slice_methods.insert(member.clone());
                }
                return member;
            }
            ExprKind::Index { obj, index, .. } => {
                let obj = self.expr(obj, names, env, owner);
                self.expr(index, names, env, owner);
                let step = match &index.kind {
                    ExprKind::Str(key) => Step::Field(key.clone()),
                    _ => Step::Index,
                };
                let member = obj.map(|p| p.at(step));
                if matches!(&index.kind, ExprKind::Str(key) if key == "slice")
                    && let Some(member) = &member
                {
                    self.slice_methods.insert(member.clone());
                }
                return member;
            }
            ExprKind::NonNull(e)
            | ExprKind::As(e, _)
            | ExprKind::Chain(e)
            | ExprKind::Await(e)
            | ExprKind::Yield { arg: Some(e), .. } => {
                return self.expr(e, names, env, owner);
            }
            ExprKind::Yield { arg: None, .. } => {}
            ExprKind::Update { target, .. } => {
                if let Some(p) = self.expr(target, names, env, owner) {
                    self.record(EffectSet::WriteEvents, e.span, p.clone());
                    self.numeric.insert(p.clone());
                    self.record(EffectSet::Events, e.span, p.clone());
                    self.record(EffectSet::FunctionNumeric, owner, p.clone());
                    self.record(EffectSet::FunctionWrites, owner, p);
                }
                self.history.entry(owner).or_default().insert(e.span);
            }
            ExprKind::Assign { target, value, op } => {
                let target = self.expr(target, names, env, owner);
                let src = self.expr(value, names, env, owner);
                if let Some(p) = &target {
                    self.record(EffectSet::WriteEvents, e.span, p.clone());
                    self.record(EffectSet::FunctionWrites, owner, p.clone());
                    if self.javascript_functions.contains(&owner) && !p.path.is_empty() {
                        let dynamic = p.at(Step::DynamicValue);
                        self.record(EffectSet::WriteEvents, e.span, dynamic.clone());
                        self.record(EffectSet::FunctionWrites, owner, dynamic.clone());
                        self.record(EffectSet::Events, e.span, dynamic.clone());
                        self.record(EffectSet::FunctionNumeric, owner, dynamic);
                    }
                    if matches!(op, Some(AssignOp::Bin(_))) {
                        self.numeric.insert(p.clone());
                        self.record(EffectSet::Events, e.span, p.clone());
                        self.record(EffectSet::FunctionNumeric, owner, p.clone());
                    }
                }
                self.copy(src, target.clone(), owner);
                self.history.entry(owner).or_default().insert(e.span);
                return target;
            }
            // A destructuring assignment writes each leaf target from part of the value.
            ExprKind::AssignPattern(pattern, value) => {
                let src = self.expr(value, names, env, owner);
                self.assign_pattern(pattern, src.clone(), names, env, owner, e.span);
                self.history.entry(owner).or_default().insert(e.span);
                return src;
            }
            // `delete` writes its target place: the property becomes absent.
            ExprKind::Delete(target) => {
                if let Some(p) = self.expr(target, names, env, owner) {
                    self.record(EffectSet::WriteEvents, e.span, p.clone());
                    self.record(EffectSet::FunctionWrites, owner, p.clone());
                    self.record(EffectSet::Events, e.span, p);
                }
                self.history.entry(owner).or_default().insert(e.span);
                return None;
            }
            ExprKind::Call { callee, args, .. } | ExprKind::New { callee, args, .. } => {
                let callee_value = self.expr(callee, names, env, owner);
                let argv: Vec<_> = args
                    .iter()
                    .map(|arg| match arg {
                        ArrEl::Item(expr) => self.expr(expr, names, env, owner),
                        ArrEl::Spread(expr) => self.expr(expr, names, env, owner).map(|value| value.at(Step::Index)),
                    })
                    .collect();
                let spreads: IndexSet<_> = args
                    .iter()
                    .enumerate()
                    .filter_map(|(index, arg)| matches!(arg, ArrEl::Spread(_)).then_some(index))
                    .collect();
                if matches!(&callee.kind, ExprKind::Ident(n) if n == "super")
                    && let Some(Value::Class(class)) = env.lookup(":class").map(|b| b.value)
                    && let Some(base) = &class.base
                {
                    let root = self.class_place(base);
                    let receiver = self.ident("this", names, env);
                    self.calls.push(Call {
                        receiver,
                        callee: root.at(Step::Field(":constructor".into())),
                        args: argv,
                        spreads,
                        result: value.clone(),
                        owner,
                        span: e.span,
                        prior: self.history.get(&owner).cloned().unwrap_or_default(),
                    });
                    self.history.entry(owner).or_default().insert(e.span);
                    return Some(value);
                }
                if matches!(e.kind, ExprKind::New { .. })
                    && let ExprKind::Ident(name) = &callee.kind
                    && let Some(Value::Class(class)) = env.lookup(name).map(|b| b.value)
                {
                    let class_place = self.class_place(&class);
                    self.copy(
                        Some(class_place.at(Step::Field(":prototype".into()))),
                        Some(value.clone()),
                        owner,
                    );
                    self.calls.push(Call {
                        receiver: Some(value.clone()),
                        callee: class_place.at(Step::Field(":constructor".into())),
                        args: argv,
                        spreads,
                        result: Place::new(e.span).at(Step::Result),
                        owner,
                        span: e.span,
                        prior: self.history.get(&owner).cloned().unwrap_or_default(),
                    });
                    let mut ancestor = Some(class);
                    while let Some(class) = ancestor {
                        let root = self.class_place(&class);
                        for field in class.class.fields.iter().filter(|f| !f.is_static && f.init.is_some()) {
                            self.calls.push(Call {
                                receiver: Some(value.clone()),
                                callee: root
                                    .at(Step::Field(":fields".into()))
                                    .at(Step::Field(field.name.clone())),
                                args: vec![],
                                spreads: IndexSet::new(),
                                result: value.at(Step::Field(field.name.clone())),
                                owner,
                                span: e.span,
                                prior: self.history.get(&owner).cloned().unwrap_or_default(),
                            });
                        }
                        ancestor = class.base.clone();
                    }
                    self.history.entry(owner).or_default().insert(e.span);
                    return Some(value);
                }
                // Known scalar/container builtins do not retain their arguments or
                // execute user code. Callback methods still form a call boundary.
                let builtin = matches!(&callee.kind, ExprKind::Member { prop, .. } if matches!(prop.as_str(),
                    "indexOf" | "lastIndexOf" | "includes" | "join" | "slice" | "at" | "concat" | "flat"
                    | "push" | "pop" | "shift" | "unshift" | "splice" | "reverse" | "toReversed" | "fill"
                    | "with" | "keys" | "values" | "entries" | "fromEntries" | "hasOwn" | "isArray"
                    | "trim" | "trimStart" | "trimEnd" | "toLowerCase" | "toUpperCase" | "startsWith" | "endsWith"
                    | "substring" | "substr" | "charAt" | "charCodeAt" | "codePointAt" | "split" | "repeat" | "padStart" | "padEnd"
                    | "abs" | "ceil" | "floor" | "round" | "trunc" | "min" | "max" | "pow" | "sqrt" | "sign"
                    | "isFinite" | "isInteger" | "isNaN" | "parseInt" | "parseFloat" | "parse" | "stringify"));
                let known_callback = match &callee.kind {
                    ExprKind::Member { prop, .. } => {
                        let index = match prop.as_str() {
                            "map" | "flatMap" | "forEach" | "filter" | "some" | "every" | "find" | "findLast"
                            | "findIndex" | "findLastIndex" | "reduce" | "sort" | "toSorted" => Some(0),
                            "replace" | "replaceAll" | "from" => Some(1),
                            _ => None,
                        };
                        index
                            .and_then(|index| argv.get(index))
                            .and_then(Option::as_ref)
                            .is_some_and(|callback| self.values.iter().any(|(value, _)| callable_at(value, callback)))
                    }
                    _ => false,
                };
                let builtin = builtin
                    || known_callback
                    || matches!(&callee.kind, ExprKind::Ident(name) if name == "RegExp" && self.ident(name, names, env).is_none())
                    || matches!(&callee.kind, ExprKind::Member { prop, .. } if prop == "match")
                    || matches!(&callee.kind, ExprKind::Member { obj, prop, .. } if prop == "from" && argv.len() == 1 && matches!(&obj.kind, ExprKind::Ident(n) if n == "Array"))
                    || matches!(&callee.kind, ExprKind::Member { prop, .. } if matches!(prop.as_str(), "replace" | "replaceAll") && matches!(args.get(1), Some(ArrEl::Item(Expr { kind: ExprKind::Str(_), .. }))));
                if !builtin {
                    self.call_events.insert(e.span, owner);
                    self.escaped.extend(argv.iter().flatten().cloned());
                }
                let fresh_result = matches!(&callee.kind, ExprKind::Member { prop, .. } if matches!(prop.as_str(), "map" | "filter" | "reduce" | "concat" | "slice" | "flat" | "flatMap" | "toReversed" | "toSorted" | "split" | "match" | "keys" | "values" | "entries" | "from" | "fromEntries" | "replace" | "replaceAll" | "trim" | "trimStart" | "trimEnd" | "toUpperCase" | "toLowerCase"));
                if !fresh_result {
                    self.external.insert(value.binding);
                }
                let borrowed_call = match &callee.kind {
                    ExprKind::Member { prop, .. } => matches!(prop.as_str(), "call" | "apply"),
                    ExprKind::Index { .. } => true,
                    _ => false,
                };
                if borrowed_call
                    && let Some(method) = callee_value.as_ref().and_then(|callee| {
                        let mut method = callee.clone();
                        method.path.pop()?;
                        Some(method)
                    })
                    && let Some(Some(receiver)) = argv.first()
                {
                    self.borrowed_slices
                        .push((method, receiver.clone(), value.clone(), owner));
                }
                if let ExprKind::Member { prop, .. } = &callee.kind {
                    // The receiver has already been visited above. Resolve its place without replaying effects.
                    let recv = callee_value.as_ref().and_then(|p| {
                        let mut p = p.clone();
                        p.path.pop()?;
                        Some(p)
                    });
                    let mutation = crate::builtins::array_mutation(prop);
                    if mutation.is_some()
                        && let Some(p) = &recv
                    {
                        let target = p.at(Step::Index);
                        if self.javascript_functions.contains(&owner) {
                            let dynamic = target.at(Step::DynamicValue);
                            self.record(EffectSet::WriteEvents, e.span, dynamic.clone());
                            self.record(EffectSet::FunctionWrites, owner, dynamic.clone());
                            self.record(EffectSet::Events, e.span, dynamic.clone());
                            self.record(EffectSet::FunctionNumeric, owner, dynamic);
                        }
                        self.record(EffectSet::WriteEvents, e.span, target.clone());
                        self.record(EffectSet::FunctionWrites, owner, target);
                        let resized = p.at(Step::Resize);
                        self.record(EffectSet::WriteEvents, e.span, resized.clone());
                        self.record(EffectSet::FunctionWrites, owner, resized);
                    }
                    if let Some(mutation) = mutation
                        && let Some(recv) = &recv
                    {
                        let inserted = mutation.inserted(&argv);
                        for src in inserted {
                            self.copy(src.clone(), Some(recv.at(Step::Index)), owner);
                        }
                    }
                    let element = recv.as_ref().map(|p| p.at(Step::Index));
                    let callback = match prop.as_str() {
                        "map" | "flatMap" => {
                            Some((0, vec![element.clone(), None, recv.clone()], value.at(Step::Index)))
                        }
                        "forEach" | "filter" | "some" | "every" | "find" | "findLast" | "findIndex"
                        | "findLastIndex" => {
                            Some((0, vec![element.clone(), None, recv.clone()], value.at(Step::Result)))
                        }
                        "reduce" => {
                            let acc = value.clone();
                            let initial = argv.get(1).cloned().flatten().or(element.clone());
                            self.copy(initial.clone(), Some(acc.clone()), owner);
                            Some((0, vec![initial, element.clone(), None, recv.clone()], acc))
                        }
                        "sort" | "toSorted" => {
                            Some((0, vec![element.clone(), element.clone()], value.at(Step::Result)))
                        }
                        "replace" | "replaceAll" => Some((1, vec![None; 5], value.at(Step::Result))),
                        "from"
                            if matches!(&callee.kind, ExprKind::Member { obj, .. }
                            if matches!(&obj.kind, ExprKind::Ident(n) if n == "Array")
                                && self.ident("Array", names, env).is_none()) =>
                        {
                            Some((
                                1,
                                vec![argv.first().cloned().flatten().map(|p| p.at(Step::Index)), None],
                                value.at(Step::Index),
                            ))
                        }
                        _ => None,
                    };
                    if let Some((index, args, result)) = callback
                        && let Some(Some(callback)) = argv.get(index)
                    {
                        if prop == "reduce" {
                            self.calls.push(Call {
                                receiver: None,
                                callee: callback.clone(),
                                args: vec![Some(result.clone()), element.clone(), None, recv.clone()],
                                spreads: IndexSet::new(),
                                result: result.clone(),
                                owner,
                                span: e.span,
                                prior: self
                                    .history
                                    .get(&owner)
                                    .into_iter()
                                    .flatten()
                                    .copied()
                                    .chain(std::iter::once(e.span))
                                    .collect(),
                            });
                        }
                        self.calls.push(Call {
                            receiver: None,
                            callee: callback.clone(),
                            args,
                            spreads: IndexSet::new(),
                            result: result.clone(),
                            owner,
                            span: e.span,
                            prior: self
                                .history
                                .get(&owner)
                                .into_iter()
                                .flatten()
                                .copied()
                                .chain(std::iter::once(e.span))
                                .collect(),
                        });
                    }
                    match prop.as_str() {
                        "flatMap" => self.copy(
                            Some(value.at(Step::Index).at(Step::Index)),
                            Some(value.at(Step::Index)),
                            owner,
                        ),
                        "filter" | "sort" | "toSorted" | "reverse" | "toReversed" | "slice" | "splice" | "fill" => {
                            self.copy(element, Some(value.at(Step::Index)), owner)
                        }
                        "find" | "findLast" | "at" | "pop" | "shift" => self.copy(element, Some(value.clone()), owner),
                        "concat" => {
                            self.copy(element, Some(value.at(Step::Index)), owner);
                            for arg in &argv {
                                self.copy(arg.clone(), Some(value.at(Step::Index)), owner);
                                self.copy(
                                    arg.as_ref().map(|p| p.at(Step::Index)),
                                    Some(value.at(Step::Index)),
                                    owner,
                                );
                            }
                        }
                        "flat" => {
                            self.copy(element, Some(value.at(Step::Index)), owner);
                            let depth = match args.first() {
                                None => Some(1),
                                Some(ArrEl::Item(Expr {
                                    kind: ExprKind::Num(n), ..
                                })) => Some(n.max(0.0) as usize),
                                _ => None,
                            };
                            if let Some(mut src) = recv.clone() {
                                if let Some(depth @ 0..=1) = depth {
                                    for _ in 0..=depth {
                                        src = src.at(Step::Index);
                                    }
                                } else {
                                    src = src.at(Step::Descendants);
                                }
                                self.copy(Some(src), Some(value.at(Step::Index)), owner);
                            }
                        }
                        _ => {}
                    }
                    if let ExprKind::Member { obj, .. } = &callee.kind
                        && let ExprKind::Ident(global) = &obj.kind
                        && self.ident(global, names, env).is_none()
                    {
                        let source = argv.first().cloned().flatten();
                        match (global.as_str(), prop.as_str()) {
                            ("Array", "from") if argv.len() == 1 => {
                                self.copy(source.map(|p| p.at(Step::Index)), Some(value.at(Step::Index)), owner)
                            }
                            ("Object", "values") => {
                                self.copy(source.map(|p| p.at(Step::Index)), Some(value.at(Step::Index)), owner)
                            }
                            ("Object", "entries") => self.copy(
                                source.map(|p| p.at(Step::Index)),
                                Some(value.at(Step::Index).at(Step::Index)),
                                owner,
                            ),
                            ("Object", "fromEntries") => self.copy(
                                source.map(|p| p.at(Step::Index).at(Step::Index)),
                                Some(value.at(Step::Index)),
                                owner,
                            ),
                            _ => {}
                        }
                    }
                }
                if let Some(callee) = callee_value {
                    let receiver = if matches!(&e.kind, ExprKind::Call { callee, .. } if matches!(&callee.kind, ExprKind::Member { obj, .. } if matches!(&obj.kind, ExprKind::Ident(n) if n == "super")))
                    {
                        self.ident("this", names, env)
                    } else if matches!(&e.kind, ExprKind::Call { callee, .. } if matches!(callee.kind, ExprKind::Member { .. }))
                    {
                        let mut p = callee.clone();
                        p.path.pop().map(|_| p)
                    } else {
                        None
                    };
                    self.calls.push(Call {
                        receiver,
                        callee,
                        args: argv,
                        spreads,
                        result: value.clone(),
                        owner,
                        span: e.span,
                        prior: self.history.get(&owner).cloned().unwrap_or_default(),
                    });
                }
                self.history.entry(owner).or_default().insert(e.span);
            }
            ExprKind::Array(items) => {
                for item in items {
                    let (ArrEl::Item(e) | ArrEl::Spread(e)) = item;
                    let src = self.expr(e, names, env, owner);
                    let src = if matches!(item, ArrEl::Spread(_)) {
                        src.map(|p| p.at(Step::Index))
                    } else {
                        src
                    };
                    self.copy(src, Some(value.at(Step::Index)), owner);
                }
            }
            ExprKind::Object(props) => {
                for prop in props {
                    match prop {
                        Prop::KV(key, e) => {
                            let src = self.expr(e, names, env, owner);
                            let step = match key {
                                PropKey::Static(k) => Step::Field(k.clone()),
                                PropKey::Computed(e) => {
                                    self.expr(e, names, env, owner);
                                    Step::Index
                                }
                            };
                            self.copy(src, Some(value.at(step)), owner);
                        }
                        Prop::Spread(e) => {
                            let src = self.expr(e, names, env, owner);
                            self.copy(src, Some(value.clone()), owner);
                        }
                        Prop::NullPrototype => {}
                    }
                }
            }
            ExprKind::Cond(test, a, b) => {
                self.expr(test, names, env, owner);
                for e in [a, b] {
                    let src = self.expr(e, names, env, owner);
                    self.copy(src, Some(value.clone()), owner);
                }
            }
            ExprKind::Logical(_, a, b) => {
                for e in [a, b] {
                    let src = self.expr(e, names, env, owner);
                    self.copy(src, Some(value.clone()), owner);
                }
            }
            ExprKind::Binary(_, a, b) | ExprKind::Instanceof(a, b) | ExprKind::In(a, b) => {
                self.expr(a, names, env, owner);
                self.expr(b, names, env, owner);
                return None;
            }
            ExprKind::Unary(_, e) | ExprKind::IsNullish(e, _) => {
                self.expr(e, names, env, owner);
                return None;
            }
            ExprKind::Template(_, exprs) => {
                for e in exprs {
                    self.expr(e, names, env, owner);
                }
                return None;
            }
            ExprKind::Num(_)
            | ExprKind::BigInt(_)
            | ExprKind::Str(_)
            | ExprKind::Bool(_)
            | ExprKind::Null
            | ExprKind::Undefined => {
                return None;
            }
            ExprKind::Class(_) | ExprKind::Regex { .. } | ExprKind::TemplateStrings { .. } => {}
        }
        Some(value)
    }

    fn block(&mut self, body: &[Stmt], names: &mut Names, env: &Rc<Scope>, owner: Span) {
        names.push(IndexMap::new());
        for s in body {
            match s {
                Stmt::Decl { kind, pat, .. } if *kind != DeclKind::Var => self.bind(pat, names, owner),
                Stmt::Func(f) => {
                    names
                        .last_mut()
                        .unwrap()
                        .insert(f.name.clone().unwrap(), Place::new(f.span));
                }
                _ => {}
            }
        }
        for s in body {
            self.stmt(s, names, env, owner);
        }
        names.pop();
    }

    fn stmt(&mut self, s: &Stmt, names: &mut Names, env: &Rc<Scope>, owner: Span) {
        match s {
            Stmt::Decl {
                kind, pat, init, span, ..
            } => {
                let src = init.as_ref().and_then(|e| self.expr(e, names, env, owner));
                if *kind == DeclKind::Var
                    && let Pat::Id(name, _) = pat
                {
                    let target = self.ident(name, names, env);
                    if init.is_some()
                        && let Some(target) = &target
                    {
                        self.record(EffectSet::WriteEvents, *span, target.clone());
                        self.record(EffectSet::FunctionWrites, owner, target.clone());
                        self.history.entry(owner).or_default().insert(*span);
                    }
                    self.copy(src, target, owner);
                } else {
                    self.bind_value(pat, src, owner);
                }
                self.defaults(pat, names, env, owner);
            }
            Stmt::Func(f) => {
                self.created_by.insert(f.span, owner);
                self.created_prior
                    .entry(f.span)
                    .or_insert_with(|| self.history.get(&owner).cloned().unwrap_or_default());
                self.function(f, names, env);
            }
            Stmt::Expr(e) | Stmt::Throw(e) | Stmt::ExportDefault(e) => {
                self.expr(e, names, env, owner);
            }
            Stmt::Return(Some(e), _) => {
                let src = self.expr(e, names, env, owner);
                if !self.scalar_functions.contains(&owner) {
                    self.copy(src, Some(Place::new(owner).at(Step::Result)), owner);
                }
            }
            Stmt::If(e, a, b) => {
                self.expr(e, names, env, owner);
                self.block(a, names, env, owner);
                if let Some(b) = b {
                    self.block(b, names, env, owner);
                }
            }
            Stmt::Block(b) => self.block(b, names, env, owner),
            Stmt::While(e, b) => {
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                self.expr(e, names, env, owner);
                self.block(b, names, env, owner);
                self.loop_history(owner, first_call, &before, e.span);
            }
            Stmt::DoWhile(b, e) => {
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                self.block(b, names, env, owner);
                self.expr(e, names, env, owner);
                self.loop_history(owner, first_call, &before, e.span);
            }
            Stmt::Labeled(_, s, _) => self.stmt(s, names, env, owner),
            Stmt::For {
                init,
                test,
                update,
                body,
                span,
            } => {
                names.push(IndexMap::new());
                if let Some(init) = init {
                    if let Stmt::Decl { kind, pat, .. } = init.as_ref()
                        && *kind != DeclKind::Var
                    {
                        self.bind(pat, names, owner);
                    }
                    self.stmt(init, names, env, owner);
                }
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                for e in [test, update].into_iter().flatten() {
                    self.expr(e, names, env, owner);
                }
                self.block(body, names, env, owner);
                self.loop_history(owner, first_call, &before, *span);
                names.pop();
            }
            Stmt::ForOf {
                kind,
                pat,
                iter,
                body,
                span,
                ..
            } => {
                let src = self.expr(iter, names, env, owner).map(|p| p.at(Step::Index));
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                names.push(IndexMap::new());
                if *kind != DeclKind::Var {
                    self.bind(pat, names, owner);
                }
                if *kind == DeclKind::Var
                    && let Pat::Id(name, _) = pat
                {
                    let target = self.ident(name, names, env);
                    self.copy(src, target, owner);
                } else {
                    self.bind_value(pat, src, owner);
                }
                self.defaults(pat, names, env, owner);
                self.block(body, names, env, owner);
                self.loop_history(owner, first_call, &before, *span);
                names.pop();
            }
            Stmt::ForIn {
                kind,
                target,
                iter,
                body,
                span,
            } => {
                self.expr(iter, names, env, owner);
                let first_call = self.calls.len();
                let before = self.history.get(&owner).cloned().unwrap_or_default();
                names.push(IndexMap::new());
                if matches!(kind, Some(DeclKind::Let | DeclKind::Const))
                    && let ExprKind::Ident(name) = &target.kind
                {
                    names
                        .last_mut()
                        .expect("scope")
                        .insert(name.clone(), Place::new(target.span));
                    self.bindings.insert(target.span, owner);
                } else if let Some(target) = self.expr(target, names, env, owner) {
                    self.record(EffectSet::WriteEvents, *span, target.clone());
                    self.record(EffectSet::FunctionWrites, owner, target);
                    self.history.entry(owner).or_default().insert(*span);
                }
                self.block(body, names, env, owner);
                self.loop_history(owner, first_call, &before, *span);
                names.pop();
            }
            Stmt::Try {
                block,
                param,
                handler,
                finalizer,
            } => {
                self.block(block, names, env, owner);
                if let Some(body) = handler {
                    names.push(IndexMap::new());
                    if let Some((name, span)) = param {
                        names.last_mut().unwrap().insert(name.clone(), Place::new(*span));
                    }
                    self.block(body, names, env, owner);
                    names.pop();
                }
                if let Some(body) = finalizer {
                    self.block(body, names, env, owner);
                }
            }
            Stmt::Switch { disc, cases, .. } => {
                self.expr(disc, names, env, owner);
                for (e, body) in cases {
                    if let Some(e) = e {
                        self.expr(e, names, env, owner);
                    }
                    self.block(body, names, env, owner);
                }
            }
            Stmt::Exported(s) => self.stmt(s, names, env, owner),
            Stmt::Return(None, _)
            | Stmt::Break(..)
            | Stmt::Continue(..)
            | Stmt::TypeAlias { .. }
            | Stmt::Import { .. }
            | Stmt::ExportNames { .. }
            | Stmt::ExportAll { .. } => {}
        }
    }

    fn call_bindings(&self, call: &Call, function: Span) -> Vec<(Option<Place>, Place)> {
        let parameters = &self.functions[&function];
        let rest = self.rest_parameters.get(&function).copied();
        let mut bindings = Vec::new();
        let mut minimum = 0;
        let mut shifted = false;
        for (argument, spread) in call.arguments(self.methods.contains(&function)) {
            shifted |= spread;
            let end = if shifted {
                parameters.len().max(minimum + 1)
            } else {
                minimum + 1
            };
            for index in minimum..end {
                let parameter = if let Some(rest) = rest.filter(|rest| index >= *rest) {
                    parameters[rest].at(Step::Index)
                } else if let Some(parameter) = parameters.get(index) {
                    parameter.clone()
                } else {
                    continue;
                };
                bindings.push((argument.clone(), parameter));
            }
            if !spread {
                minimum += 1;
            }
        }
        bindings
    }

    fn caller_effects(&self, function: Span, bindings: &[(Option<Place>, Place)], effect: &Place) -> CallerEffects {
        if self.functions[&function]
            .iter()
            .any(|param| param.binding == effect.binding)
        {
            CallerEffects::Mapped(
                bindings
                    .iter()
                    .filter_map(|(argument, parameter)| {
                        if parameter.binding != effect.binding {
                            return None;
                        }
                        let suffix = projected_suffix(&effect.path, &parameter.path)?;
                        // Assigning a parameter or a fresh rest slot cannot replace
                        // the caller's binding.
                        if suffix.is_empty() {
                            return None;
                        }
                        let mut target = argument.as_ref()?.clone();
                        target.path.extend_from_slice(suffix);
                        Some(derived(target, self.max_depth, &self.widened))
                    })
                    .collect(),
            )
        } else if self.bindings.get(&effect.binding) != Some(&function) {
            CallerEffects::Same
        } else {
            CallerEffects::Mapped(Vec::new())
        }
    }

    fn loop_history(&mut self, owner: Span, first_call: usize, before: &IndexSet<Span>, key: Span) {
        let events: IndexSet<_> = self
            .history
            .get(&owner)
            .into_iter()
            .flat_map(|events| events.difference(before).copied())
            .collect();
        self.loop_events.insert(key, events.clone());
        for call in &mut self.calls[first_call..] {
            if call.owner == owner {
                call.prior.extend(&events);
            }
        }
    }

    /// The places one alias step reaches from `place`, numbered. Every place set closes under
    /// the same step, so a pass computes it once per place and every set shares the result.
    fn alias_targets(&mut self, place: Id, solved: &mut Solved) -> Rc<[Id]> {
        let index = place as usize;
        if let Some(Some(targets)) = solved.alias_targets.get(index) {
            return targets.clone();
        }
        let p = self.places[index].clone();
        let mut targets = Vec::new();
        for (source, target) in solved.aliases.get(&p.binding).into_iter().flatten() {
            let Some(suffix) = projected_suffix(&p.path, &source.path) else {
                continue;
            };
            // Copying a slot retains its value's aliases, not future replacements of that slot.
            if suffix.is_empty() || suffix == [Step::DynamicValue] {
                continue;
            }
            let mut alias = target.clone();
            alias.path.extend_from_slice(suffix);
            targets.push(intern(&mut self.places, derived(alias, self.max_depth, &self.widened)));
        }
        let targets: Rc<[Id]> = targets.into();
        if solved.alias_targets.len() <= index {
            solved.alias_targets.resize(index + 1, None);
        }
        solved.alias_targets[index] = Some(targets.clone());
        targets
    }

    /// Closes one set under aliases, starting from `pending`. A JavaScript-owned binding whose group
    /// in this set grows past `RUNAWAY_ABOVE` here is widened on the spot, so one pass can't run away
    /// before the end-of-pass check sees it. The places it already added stay, and the solve ends
    /// with the binding among those to widen, so the restart computes the result without them.
    fn expand_aliases(
        &mut self,
        set: &mut IdSet,
        mut pending: Vec<Id>,
        solved: &mut Solved,
        runaway: &mut IndexSet<Span>,
    ) {
        let mut groups: IndexMap<Span, usize> = IndexMap::new();
        while let Some(place) = pending.pop() {
            for &alias in self.alias_targets(place, solved).iter() {
                if !set.insert(alias) {
                    continue;
                }
                pending.push(alias);
                let p = &self.places[alias as usize];
                if p.path.len() > 1 && !self.widened.contains(&p.binding) {
                    let size = groups.entry(p.binding).or_default();
                    *size += 1;
                    if *size > RUNAWAY_ABOVE && self.javascript_owned(p.binding) {
                        let binding = p.binding;
                        self.widened.insert(binding);
                        runaway.insert(binding);
                        // Targets computed before the binding was widened are still exact places.
                        solved.alias_targets.clear();
                    }
                }
            }
        }
    }

    /// A binding owned by imported JavaScript, or the span of such a function.
    fn javascript_owned(&self, binding: Span) -> bool {
        self.javascript_functions.contains(&binding)
            || self
                .bindings
                .get(&binding)
                .is_some_and(|owner| self.javascript_functions.contains(owner))
    }

    /// Every (call, callee) pair: a call in reachable code and a function value at its callee.
    fn call_edges(&self) -> Vec<(usize, Span)> {
        let mut values: IndexMap<Span, Vec<&(Place, Span)>> = IndexMap::new();
        for value in &self.values {
            values.entry(value.0.binding).or_default().push(value);
        }
        let mut edges = Vec::new();
        let mut linked = IndexSet::new();
        for (index, call) in self.calls.iter().enumerate() {
            if !self.reachable.contains(&call.owner) {
                continue;
            }
            for (value, function) in values.get(&call.callee.binding).into_iter().flatten() {
                if callable_at(value, &call.callee) && linked.insert((index, *function)) {
                    edges.push((index, *function));
                }
            }
        }
        edges
    }

    /// Carries each callee's entry views and summaries across its call edges, reading only what
    /// each edge hasn't read yet. Every output is a function of the edge and one input place.
    fn call_summaries(&mut self, solved: &mut Solved) {
        let max_depth = self.max_depth;
        let widened = &self.widened;
        for edge in self.call_edges() {
            let (index, function) = edge;
            let call = &self.calls[index];
            let bindings = self.call_bindings(call, function);
            let read = solved.edges.entry(edge).or_default();
            // Use writes that can precede this call, rather than later writes elsewhere in the
            // caller. Captures keep their stable view because closures can run later.
            let arguments: Vec<_> = bindings
                .iter()
                .filter_map(|(arg, param)| {
                    let arg = arg.as_ref()?;
                    Some((arg, param, self.bindings.get(&arg.binding) != Some(&call.owner)))
                })
                .collect();
            let project = |inputs: &mut dyn Iterator<Item = &Place>, captured_only: bool, out: &mut Vec<Place>| {
                for p in inputs {
                    for (arg, param, captures) in &arguments {
                        if (*captures || !captured_only)
                            && p.binding == arg.binding
                            && let Some(path) = projected_suffix(&p.path, &arg.path)
                        {
                            let mut target = (*param).clone();
                            target.path.extend_from_slice(path);
                            out.push(derived(target, max_depth, widened));
                        }
                    }
                }
            };
            // Entry views: [numeric, writes].
            let mut views = [Vec::new(), Vec::new()];
            if !arguments.is_empty() {
                for (set, cursor, captured_only, view) in [
                    (&self.incoming, &mut read.incoming, false, 0),
                    (&self.numeric, &mut read.numeric, true, 0),
                    (&self.incoming_writes, &mut read.incoming_writes, false, 1),
                    (&self.writes, &mut read.writes, true, 1),
                ] {
                    project(&mut unread(set, *cursor, set.len()), captured_only, &mut views[view]);
                    *cursor = set.len();
                }
                // Prior events are large and shared by many calls; read only the places of
                // this edge's argument bindings, through a per-event index by binding.
                let [event_index, write_index] = &mut solved.event_bindings;
                for event in &call.prior {
                    for (sets, indexes, cursors, view) in [
                        (&self.events, &mut *event_index, &mut read.events, 0),
                        (&self.write_events, &mut *write_index, &mut read.write_events, 1),
                    ] {
                        let Some(set) = sets.get(event) else {
                            continue;
                        };
                        let index = indexes.entry(*event).or_default();
                        index.extend(set, &self.places);
                        let cursor = cursors.entry(*event).or_default();
                        let mut inputs = arguments
                            .iter()
                            .map(|(arg, _, _)| arg.binding)
                            .collect::<IndexSet<_>>()
                            .into_iter()
                            .flat_map(|binding| index.unread(binding, *cursor))
                            .map(|position| &self.places[set.order[position] as usize]);
                        project(&mut inputs, false, &mut views[view]);
                        *cursor = set.len();
                    }
                }
            }
            let [incoming, incoming_writes] = views;
            // A summary place below a parameter maps to the argument; a place the callee doesn't
            // own passes through as the same place number.
            let mut numeric = Vec::new();
            let mut writes = Vec::new();
            for (summaries, cursor, out) in [
                (&self.function_numeric, &mut read.function_numeric, &mut numeric),
                (&self.function_writes, &mut read.function_writes, &mut writes),
            ] {
                if let Some(summary) = summaries.get(&function) {
                    for &id in summary.unread(*cursor, summary.len()) {
                        match self.caller_effects(function, &bindings, &self.places[id as usize]) {
                            CallerEffects::Same => out.push(Ok(id)),
                            CallerEffects::Mapped(places) => out.extend(places.into_iter().map(Err)),
                        }
                    }
                    *cursor = summary.len();
                }
            }
            let (span, owner) = (call.span, call.owner);
            self.incoming.extend(incoming);
            self.incoming_writes.extend(incoming_writes);
            let mut number = |target: std::result::Result<Id, Place>| match target {
                Ok(id) => id,
                Err(place) => intern(&mut self.places, place),
            };
            let numeric: Vec<Id> = numeric.into_iter().map(&mut number).collect();
            let writes: Vec<Id> = writes.into_iter().map(&mut number).collect();
            for id in numeric {
                self.events.entry(span).or_default().insert(id);
                self.function_numeric.entry(owner).or_default().insert(id);
                self.numeric.insert(self.places[id as usize].clone());
            }
            for id in writes {
                self.write_events.entry(span).or_default().insert(id);
                self.function_writes.entry(owner).or_default().insert(id);
            }
        }
    }

    fn sizes(&self) -> [usize; 13] {
        let total = |sets: &IndexMap<Span, IdSet>| sets.values().map(IdSet::len).sum::<usize>();
        [
            self.reachable.len(),
            self.incoming.len(),
            self.incoming_writes.len(),
            self.values.len(),
            self.slice_methods.len(),
            self.copies.len(),
            self.numeric.len(),
            self.writes.len(),
            total(&self.function_numeric),
            total(&self.function_writes),
            total(&self.events),
            total(&self.write_events),
            self.escaped.len(),
        ]
    }

    /// JavaScript-owned bindings, not yet widened, whose group in some effect set is larger
    /// than `WIDEN_ABOVE`. Sets only grow within a solve, so each group is counted once per
    /// place, from where the previous pass stopped.
    fn widening_candidates(&self, solved: &mut Solved) -> IndexSet<Span> {
        let mut over = IndexSet::new();
        let mut count = |groups: &mut Groups, places: &mut dyn Iterator<Item = &Place>| {
            for p in places {
                if p.path.len() > 1 {
                    let size = groups.sizes.entry(p.binding).or_default();
                    *size += 1;
                    if *size > WIDEN_ABOVE && !self.widened.contains(&p.binding) && self.javascript_owned(p.binding) {
                        over.insert(p.binding);
                    }
                }
            }
        };
        for (kind, sets) in [
            &self.events,
            &self.write_events,
            &self.function_numeric,
            &self.function_writes,
        ]
        .into_iter()
        .enumerate()
        {
            for (key, set) in sets {
                let groups = solved.groups.entry((kind, *key)).or_default();
                let read = groups.read;
                groups.read = set.len();
                count(
                    groups,
                    &mut set.unread(read, set.len()).iter().map(|id| &self.places[*id as usize]),
                );
            }
        }
        for (groups, set) in
            solved
                .plain_groups
                .iter_mut()
                .zip([&self.numeric, &self.writes, &self.incoming, &self.incoming_writes])
        {
            let read = groups.read;
            groups.read = set.len();
            count(groups, &mut unread(set, read, set.len()));
        }
        over
    }

    /// Applies every rule until none adds a fact. Each rule only adds facts, and adds more when
    /// its inputs grow, so the result is the least fixpoint whatever the order and grouping of
    /// rule applications. That lets each pass skip work an earlier pass already did: places
    /// sets are insertion-ordered, and `Solved` records how much of each set a rule has read.
    /// See docs/decisions/2026-11-08-effects-solver-scale.md.
    /// Solves with `self.widened` fixed, or returns the bindings that must be widened as well.
    fn solve(mut self) -> std::result::Result<Effects, IndexSet<Span>> {
        self.max_depth = self
            .copies
            .iter()
            .flat_map(|(a, b, _)| [a.path.len(), b.path.len()])
            .chain(self.numeric.iter().map(|p| p.path.len()))
            .chain(
                self.function_writes
                    .values()
                    .flat_map(|set| &set.order)
                    .map(|id| self.places[*id as usize].path.len()),
            )
            .max()
            .unwrap_or(0)
            + 1;
        let max_depth = self.max_depth;
        if !self.widened.is_empty() {
            let widened = &self.widened;
            let places = &mut self.places;
            let coarse: Vec<Id> = (0..places.len())
                .map(|id| intern(places, coarsened(places[id].clone(), widened)))
                .collect();
            for sets in [
                &mut self.events,
                &mut self.write_events,
                &mut self.function_numeric,
                &mut self.function_writes,
            ] {
                for set in sets.values_mut() {
                    let mut out = IdSet::default();
                    for id in &set.order {
                        out.insert(coarse[*id as usize]);
                    }
                    *set = out;
                }
            }
            let coarsen = |set: &mut Places| *set = set.drain(..).map(|p| coarsened(p, widened)).collect();
            for set in [&mut self.incoming, &mut self.incoming_writes, &mut self.writes] {
                coarsen(set);
            }
        }
        // `numeric` is the reachable functions' numeric places plus the incoming views, rebuilt
        // below. Construction also put every place it holds in its function's `function_numeric`.
        self.numeric.clear();
        let mut views: IndexMap<Span, Vec<(Span, Place)>> = IndexMap::new();
        for (binding, view) in &self.parameter_views {
            views.entry(view.binding).or_default().push((*binding, view.clone()));
        }
        let mut solved = Solved::default();
        loop {
            let before = self.sizes();
            let mut linked = Vec::new();
            for (index, function) in self.call_edges() {
                if !solved.linked.insert((index, function)) {
                    continue;
                }
                let call = &self.calls[index];
                for (arg, param) in self.call_bindings(call, function) {
                    if let Some(arg) = arg {
                        linked.push((arg, param, call.owner));
                    }
                }
                if let Some(arguments) = self
                    .argument_values
                    .get(&function)
                    .filter(|arguments| self.argument_reads.contains(&arguments.binding))
                {
                    for argument in call.args.iter().flatten() {
                        linked.push((argument.clone(), arguments.at(Step::Index), call.owner));
                    }
                }
                linked.push((Place::new(function).at(Step::Result), call.result.clone(), call.owner));
                self.reachable.insert(function);
            }
            self.copies.extend(linked);
            // Function values and slice methods flow along every reachable copy.
            let mut sources: IndexMap<Span, Vec<(&Place, &Place)>> = IndexMap::new();
            for (src, dest, owner) in &self.copies {
                if self.reachable.contains(owner) {
                    sources.entry(src.binding).or_default().push((src, dest));
                }
            }
            let mut pending: Vec<_> = self.values.iter().cloned().collect();
            while let Some((value, function)) = pending.pop() {
                for (src, dest) in sources.get(&value.binding).into_iter().flatten() {
                    if let Some(suffix) = projected_suffix(&value.path, &src.path) {
                        let mut target = (*dest).clone();
                        target.path.extend_from_slice(suffix);
                        let value = (bounded(target, max_depth), function);
                        if self.values.insert(value.clone()) {
                            pending.push(value);
                        }
                    }
                }
            }
            let mut pending: Vec<_> = self.slice_methods.iter().cloned().collect();
            while let Some(method) = pending.pop() {
                for (src, dest) in sources.get(&method.binding).into_iter().flatten() {
                    if let Some(suffix) = projected_suffix(&method.path, &src.path) {
                        let mut target = (*dest).clone();
                        target.path.extend_from_slice(suffix);
                        let target = bounded(target, max_depth);
                        if self.slice_methods.insert(target.clone()) {
                            pending.push(target);
                        }
                    }
                }
            }
            for (method, receiver, result, owner) in &self.borrowed_slices {
                if self.reachable.contains(owner) && self.slice_methods.iter().any(|value| callable_at(value, method)) {
                    self.copies
                        .insert((receiver.at(Step::Index), result.at(Step::Index), *owner));
                }
            }
            for (incoming, read) in [
                (&mut self.incoming, &mut solved.viewed_incoming),
                (&mut self.incoming_writes, &mut solved.viewed_incoming_writes),
            ] {
                while let Some(p) = incoming.get_index(*read).cloned() {
                    *read += 1;
                    for (binding, view) in views.get(&p.binding).into_iter().flatten() {
                        if let Some(path) = projected_suffix(&p.path, &view.path) {
                            incoming.insert(coarsened(
                                Place {
                                    binding: *binding,
                                    path: path.to_vec(),
                                },
                                &self.widened,
                            ));
                        }
                    }
                }
            }
            for (sets, union, read) in [
                (&self.function_numeric, &mut self.numeric, &mut solved.numeric_from),
                (&self.function_writes, &mut self.writes, &mut solved.writes_from),
            ] {
                for (owner, paths) in sets {
                    if self.reachable.contains(owner) {
                        let read = read.entry(*owner).or_default();
                        union.extend(
                            paths
                                .unread(*read, paths.len())
                                .iter()
                                .map(|id| self.places[*id as usize].clone()),
                        );
                        *read = paths.len();
                    }
                }
            }
            let end = self.incoming.len();
            self.numeric
                .extend(unread(&self.incoming, solved.numeric_incoming, end).cloned());
            solved.numeric_incoming = end;
            self.call_summaries(&mut solved);
            let mut escaped = Vec::new();
            for (src, dest, owner) in &self.copies {
                if self.reachable.contains(owner)
                    && self
                        .escaped
                        .iter()
                        .any(|e| e.binding == dest.binding && projected_suffix(&dest.path, &e.path).is_some())
                {
                    escaped.push(src.clone());
                }
            }
            self.escaped.extend(escaped);
            let end = self.escaped.len();
            let invalidated: Vec<(Option<Span>, Id)> = unread(&self.escaped, solved.invalidated, end)
                .map(|p| {
                    let id = intern(&mut self.places, coarsened(p.at(Step::Descendants), &self.widened));
                    (self.bindings.get(&p.binding).copied(), id)
                })
                .collect();
            for (event, owner) in &self.call_events {
                let set = self.write_events.entry(*event).or_default();
                for (binding_owner, id) in &invalidated {
                    if binding_owner.is_none_or(|binding_owner| binding_owner == *owner) {
                        set.insert(*id);
                    }
                }
            }
            solved.invalidated = end;
            let mut by_binding: IndexMap<Span, Vec<(usize, &Place)>> = IndexMap::new();
            for (index, e) in self.escaped.iter().enumerate() {
                by_binding.entry(e.binding).or_default().push((index, e));
            }
            let escaped = |p: &Place, from: usize| {
                by_binding.get(&p.binding).is_some_and(|escapes| {
                    escapes
                        .iter()
                        .any(|(index, e)| *index >= from && projected_suffix(&p.path, &e.path).is_some())
                })
            };
            let places = &mut self.places;
            for (event, paths) in &mut self.write_events {
                if solved.external_added.contains(event) {
                    continue;
                }
                // Earlier passes checked the places they saw against the escapes they saw.
                let (places_seen, escapes_seen) = solved.external_checked.get(event).copied().unwrap_or_default();
                let place = |id: &Id| &places[*id as usize];
                let changes_external = paths
                    .unread(places_seen, paths.len())
                    .iter()
                    .map(place)
                    .any(|p| !p.path.is_empty() && (self.external.contains(&p.binding) || escaped(p, 0)))
                    || paths
                        .unread(0, places_seen)
                        .iter()
                        .map(place)
                        .any(|p| !p.path.is_empty() && escaped(p, escapes_seen));
                if changes_external {
                    let owner = self.event_owners.get(event);
                    for binding in &self.external {
                        if owner.is_some() && self.bindings.get(binding) == owner {
                            paths.insert(intern(places, Place::new(*binding).at(Step::Descendants)));
                        }
                    }
                    solved.external_added.insert(*event);
                } else {
                    solved.external_checked.insert(*event, (paths.len(), end));
                }
            }
            let mut aliased = IndexSet::new();
            for (index, (a, b, owner)) in self.copies.iter().enumerate() {
                if self.reachable.contains(owner) && solved.aliased.insert(index) {
                    solved
                        .aliases
                        .entry(a.binding)
                        .or_default()
                        .push((a.clone(), b.clone()));
                    solved
                        .aliases
                        .entry(b.binding)
                        .or_default()
                        .push((b.clone(), a.clone()));
                    aliased.extend([a.binding, b.binding]);
                }
            }
            // New alias edges change what a place aliases.
            if !aliased.is_empty() {
                solved.alias_targets.clear();
            }
            let mut runaway = IndexSet::new();
            for set in [
                EffectSet::FunctionNumeric,
                EffectSet::FunctionWrites,
                EffectSet::Events,
                EffectSet::WriteEvents,
            ] {
                let sets = match set {
                    EffectSet::FunctionNumeric => &mut self.function_numeric,
                    EffectSet::FunctionWrites => &mut self.function_writes,
                    EffectSet::Events => &mut self.events,
                    EffectSet::WriteEvents => &mut self.write_events,
                };
                let mut taken = std::mem::take(sets);
                for (key, paths) in taken.iter_mut() {
                    // Places seen before are closed under the aliases seen before.
                    let seen = solved.expanded[set as usize].get(key).copied().unwrap_or_default();
                    let pending = paths
                        .unread(seen, paths.len())
                        .iter()
                        .chain(
                            paths
                                .unread(0, seen)
                                .iter()
                                .filter(|id| aliased.contains(&self.places[**id as usize].binding)),
                        )
                        .copied()
                        .collect();
                    self.expand_aliases(paths, pending, &mut solved, &mut runaway);
                    solved.expanded[set as usize].insert(*key, paths.len());
                }
                *match set {
                    EffectSet::FunctionNumeric => &mut self.function_numeric,
                    EffectSet::FunctionWrites => &mut self.function_writes,
                    EffectSet::Events => &mut self.events,
                    EffectSet::WriteEvents => &mut self.write_events,
                } = taken;
            }
            // Entry views only flow from a caller's prior events into that
            // invocation. Expanding them back through callback aliases would
            // move writes performed inside a function before its own entry.
            let mut over = self.widening_candidates(&mut solved);
            over.extend(runaway);
            if !over.is_empty() {
                return Err(over);
            }
            if before == self.sizes() {
                break;
            }
        }
        let mut entry_history: IndexMap<Span, IndexSet<Span>> = IndexMap::new();
        loop {
            let before = entry_history.values().map(IndexSet::len).sum::<usize>();
            for call in &self.calls {
                if !self.reachable.contains(&call.owner) {
                    continue;
                }
                let mut prior = entry_history.get(&call.owner).cloned().unwrap_or_default();
                prior.extend(&call.prior);
                for (_, function) in self.values.iter().filter(|(value, _)| callable_at(value, &call.callee)) {
                    entry_history.entry(*function).or_default().extend(&prior);
                }
            }
            if before == entry_history.values().map(IndexSet::len).sum::<usize>() {
                break;
            }
        }
        // Possible entry events invalidate reads. Only events preceding every
        // entry may be discounted at creation: two calls can create different
        // instances of the same lexical closure. Loop events may repeat after
        // creation, so they are never creation predecessors.
        let repeating: IndexSet<_> = self.loop_events.values().flatten().copied().collect();
        let mut creation_entries = entry_history.clone();
        for events in creation_entries.values_mut() {
            events.retain(|event| !repeating.contains(event));
        }
        for root in &self.roots {
            creation_entries.insert(*root, IndexSet::new());
        }
        loop {
            let mut next: IndexMap<Span, IndexSet<Span>> =
                self.roots.iter().map(|root| (*root, IndexSet::new())).collect();
            for call in &self.calls {
                if !self.reachable.contains(&call.owner) {
                    continue;
                }
                let mut prior = creation_entries.get(&call.owner).cloned().unwrap_or_default();
                prior.extend(call.prior.difference(&repeating));
                for (_, function) in self.values.iter().filter(|(value, _)| callable_at(value, &call.callee)) {
                    next.entry(*function)
                        .and_modify(|events| events.retain(|event| prior.contains(event)))
                        .or_insert_with(|| prior.clone());
                }
            }
            if next == creation_entries {
                break;
            }
            creation_entries = next;
        }
        // Event histories share most of their events; union them by place number, with one
        // membership bitset reused (and cleared) across every result set.
        let mut seen = IdSet::default();
        let mut union = |start: &[Id], events: &mut dyn Iterator<Item = &Span>| -> Vec<Id> {
            let mut out = Vec::new();
            let sets = events.filter_map(|event| self.write_events.get(event));
            for &id in start.iter().chain(sets.flat_map(|set| &set.order)) {
                if seen.insert(id) {
                    out.push(id);
                }
            }
            for id in &out {
                seen.bits[*id as usize / 64] = 0;
            }
            seen.order.clear();
            out
        };
        let mut capture_writes: IndexMap<Span, Vec<Id>> = self
            .function_writes
            .iter()
            .map(|(function, set)| (*function, set.order.clone()))
            .collect();
        for (function, prior) in &entry_history {
            let mut created = self.created_prior.get(function).cloned().unwrap_or_default();
            created.retain(|event| !repeating.contains(event));
            if let Some(owner) = self.created_by.get(function) {
                created.extend(creation_entries.get(owner).into_iter().flatten());
            }
            let mut events = prior.difference(&created).peekable();
            if events.peek().is_some() {
                let places = capture_writes.entry(*function).or_default();
                *places = union(places, &mut events);
            }
        }
        let mut summaries = |histories: &IndexMap<Span, IndexSet<Span>>| {
            histories
                .iter()
                .map(|(span, events)| (*span, union(&[], &mut events.iter())))
                .collect()
        };
        let expression_writes = summaries(&self.expression_events);
        let loop_writes = summaries(&self.loop_events);
        let mut binding_writes: IndexMap<Span, IndexSet<Vec<Step>>> = IndexMap::new();
        for (owner, paths) in &self.function_writes {
            if self.reachable.contains(owner) {
                for id in &paths.order {
                    let p = &self.places[*id as usize];
                    binding_writes.entry(p.binding).or_default().insert(p.path.clone());
                }
            }
        }
        let numbered = |sets: IndexMap<Span, IdSet>| sets.into_iter().map(|(key, set)| (key, set.order)).collect();
        let mut effects = Effects {
            binding_writes,
            reassigned: self
                .write_events
                .values()
                .flat_map(|set| &set.order)
                .map(|id| &self.places[*id as usize])
                .filter(|place| place.path.is_empty())
                .map(|place| place.binding)
                .collect(),
            captured: self.captured,
            captured_data: self.captured_data,
            numeric: IndexMap::new(),
            incoming: IndexMap::new(),
            incoming_writes: IndexMap::new(),
            capture_writes,
            events: numbered(self.events),
            write_events: numbered(self.write_events),
            expression_writes,
            loop_writes,
            places: self.places,
        };
        for p in self.numeric {
            effects.numeric.entry(p.binding).or_default().insert(p.path);
        }
        for p in self.incoming {
            effects.incoming.entry(p.binding).or_default().insert(p.path);
        }
        for p in self.incoming_writes {
            effects.incoming_writes.entry(p.binding).or_default().insert(p.path);
        }
        Ok(effects)
    }
}

/// Debug builds only: `TP_DEBUG_EFFECTS=<file>` appends every solved [`Effects`] to `<file>`
/// in a stable sorted text form, so a change to the solver can be checked against the old
/// result program by program. `TP_DEBUG_EFFECTS_WORK=<file>` appends one
/// `projections=<n> facts=<n>` line per solve instead. Release builds never read either.
#[cfg(debug_assertions)]
fn debug_dump(effects: &Effects, projections: u64, widened: &IndexSet<Span>) {
    use std::collections::BTreeSet;
    use std::io::Write as _;
    let append = |path: &std::ffi::OsStr| {
        std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(path)
            .expect("the effects debug file is writable")
    };
    if let Some(path) = std::env::var_os("TP_DEBUG_EFFECTS_WORK") {
        let places = |map: &IndexMap<Span, Vec<Id>>| map.values().map(Vec::len).sum::<usize>();
        let paths = |map: &IndexMap<Span, IndexSet<Vec<Step>>>| map.values().map(IndexSet::len).sum::<usize>();
        let facts = places(&effects.capture_writes)
            + places(&effects.events)
            + places(&effects.write_events)
            + places(&effects.expression_writes)
            + places(&effects.loop_writes)
            + paths(&effects.numeric)
            + paths(&effects.incoming)
            + paths(&effects.incoming_writes)
            + paths(&effects.binding_writes);
        writeln!(append(&path), "projections={projections} facts={facts}").unwrap();
    }
    let Some(path) = std::env::var_os("TP_DEBUG_EFFECTS") else {
        return;
    };
    let span = |s: &Span| format!("{}:{}-{}", s.file, s.start, s.end);
    let place = |p: &Place| format!("{}{:?}", span(&p.binding), p.path);
    let mut lines = BTreeSet::new();
    for s in widened {
        lines.insert(format!("widened {}", span(s)));
    }
    for s in &effects.reassigned {
        lines.insert(format!("reassigned {}", span(s)));
    }
    for s in &effects.captured {
        lines.insert(format!("captured {}", span(s)));
    }
    // Keyed by value identity (an address), so only the places are stable across runs.
    let captured_data: Vec<_> = effects
        .captured_data
        .values()
        .map(place)
        .collect::<BTreeSet<_>>()
        .into_iter()
        .collect();
    let counted = effects.captured_data.len();
    lines.insert(format!("captured_data {counted} {captured_data:?}"));
    for (name, map) in [
        ("numeric", &effects.numeric),
        ("incoming", &effects.incoming),
        ("incoming_writes", &effects.incoming_writes),
        ("binding_writes", &effects.binding_writes),
    ] {
        for (s, paths) in map {
            for p in paths {
                lines.insert(format!("{name} {} {p:?}", span(s)));
            }
        }
    }
    for (name, map) in [
        ("capture_writes", &effects.capture_writes),
        ("events", &effects.events),
        ("write_events", &effects.write_events),
        ("expression_writes", &effects.expression_writes),
        ("loop_writes", &effects.loop_writes),
    ] {
        for (s, places) in map {
            lines.insert(format!("{name} {} {}", span(s), places.len()));
            for p in places.iter().map(|id| &effects.places[*id as usize]) {
                lines.insert(format!("{name} {} {}", span(s), place(p)));
            }
        }
    }
    let mut file = append(&path);
    writeln!(file, "== effects ({} facts)", lines.len()).unwrap();
    for line in lines {
        writeln!(file, "{line}").unwrap();
    }
}

fn scalar_return_annotation(annotation: &TyAnn) -> bool {
    match annotation {
        TyAnn::Num
        | TyAnn::NumLit(_)
        | TyAnn::Str
        | TyAnn::StrLit(_)
        | TyAnn::Bool
        | TyAnn::BoolLit(_)
        | TyAnn::Null
        | TyAnn::Undefined
        | TyAnn::Void
        | TyAnn::Never => true,
        TyAnn::Union(parts) => parts.iter().all(scalar_return_annotation),
        _ => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn field(name: &str) -> Step {
        Step::Field(name.into())
    }

    fn numeric(binding: Span, path: Vec<Step>) -> Effects {
        let mut effects = Effects::default();
        effects.numeric.entry(binding).or_default().insert(path);
        effects
    }

    #[test]
    fn a_place_cut_above_a_read_view_widens_the_whole_view() {
        let binding = Span {
            file: 0,
            start: 1,
            end: 2,
        };
        let view = [field("a"), field("b"), field("x")];
        let literal = Ty::Obj(IndexMap::from([("y".into(), (Ty::NumLit(0.0), false))]));
        let number = Ty::Obj(IndexMap::from([("y".into(), (Ty::Num, false))]));
        // The solver cuts `o.a.b.x.y++` to `o.a.b.Descendants` past its depth bound.
        let cut = numeric(binding, vec![field("a"), field("b"), Step::Descendants]);
        assert_eq!(cut.read_view_type(binding, &view, &literal), number);
        let dynamic = numeric(binding, vec![field("a"), Step::DynamicDescendants]);
        assert_eq!(dynamic.read_view_type(binding, &view, &literal), Ty::Dynamic);
        // A place below the view keeps its own path, as before.
        let exact = numeric(binding, vec![field("a"), field("b"), field("x"), field("y")]);
        assert_eq!(exact.read_view_type(binding, &view, &literal), number);
        // A cut place on a sibling path, or below the view, changes nothing outside it.
        let sibling = numeric(binding, vec![field("a"), field("c"), Step::Descendants]);
        assert_eq!(sibling.read_view_type(binding, &view, &literal), literal);
        let other = numeric(binding, vec![field("z"), Step::DynamicDescendants]);
        assert_eq!(other.read_view_type(binding, &view, &literal), literal);
    }

    #[test]
    fn a_write_event_cut_above_a_view_widens_the_view() {
        let binding = Span {
            file: 0,
            start: 1,
            end: 2,
        };
        let event = Span {
            file: 0,
            start: 3,
            end: 4,
        };
        let mut effects = Effects::default();
        let id = intern(
            &mut effects.places,
            Place {
                binding,
                path: vec![field("a"), Step::DynamicDescendants],
            },
        );
        effects.events.entry(event).or_default().push(id);
        let view = [field("a"), field("b")];
        assert_eq!(effects.write_type(event, binding, &view, &Ty::NumLit(1.0)), Ty::Dynamic);
        let unrelated = Span {
            file: 0,
            start: 5,
            end: 6,
        };
        assert_eq!(
            effects.write_type(event, unrelated, &view, &Ty::NumLit(1.0)),
            Ty::NumLit(1.0)
        );
    }
}
