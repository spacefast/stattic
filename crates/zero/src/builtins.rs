//! Shared mechanical facts about the supported builtin surface.

#[derive(Clone, Copy)]
pub struct ArrayMutation {
    /// Arguments whose values become receiver elements (excluding index/count arguments).
    inserted_start: usize,
    inserted_limit: Option<usize>,
}

impl ArrayMutation {
    pub fn inserted<T>(self, args: &[T]) -> &[T] {
        let start = self.inserted_start.min(args.len());
        let end = self
            .inserted_limit
            .map_or(args.len(), |n| start.saturating_add(n).min(args.len()));
        &args[start..end]
    }
}

pub fn array_mutation(name: &str) -> Option<ArrayMutation> {
    let (inserted_start, inserted_limit) = match name {
        "push" | "unshift" => (0, None),
        "splice" => (2, None),
        "fill" => (0, Some(1)),
        "pop" | "shift" | "sort" | "reverse" => (0, Some(0)),
        _ => return None,
    };
    Some(ArrayMutation {
        inserted_start,
        inserted_limit,
    })
}
