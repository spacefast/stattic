//! `Array.prototype.sort` as Node runs it. V8 (third_party/v8/builtins/array-sort.tq) sorts with
//! TimSort, ported from CPython's listsort. This is the same algorithm step for step, so a
//! comparator is called with the same pairs in the same order as under Node. An inconsistent or
//! effectful comparator therefore gives Node's result too, not just a valid stable order.
//! runtime/TypedPress/Js.php carries the same port for compiled code; keep the two in step.
//!
//! `less(x, y)` is `ToNumber(compare(x, y)) < 0`, with NaN counting as 0. The caller removes
//! undefined elements first and appends them afterwards, as the specification requires.

const MIN_GALLOP: isize = 7;

/// Sorts `items` in place. A comparator error stops the sort; `items` is then partly sorted,
/// so callers sort a copy and write it back only on success, as V8 does.
pub fn timsort<T: Clone, E>(items: &mut [T], less: impl FnMut(&T, &T) -> Result<bool, E>) -> Result<(), E> {
    let mut state = Sort {
        work: items,
        runs: Vec::new(),
        min_gallop: MIN_GALLOP,
        less,
    };
    state.run()
}

struct Sort<'a, T, F> {
    work: &'a mut [T],
    /// Pending runs as (base, length).
    runs: Vec<(isize, isize)>,
    min_gallop: isize,
    less: F,
}

fn at<T>(items: &[T], index: isize) -> &T {
    &items[usize::try_from(index).expect("sort index")]
}

fn set<T: Clone>(items: &mut [T], index: isize, value: T) {
    items[usize::try_from(index).expect("sort index")] = value;
}

/// Copies `length` elements inside one array, from the back when the ranges overlap that way.
fn move_within<T: Clone>(items: &mut [T], src: isize, dst: isize, length: isize) {
    if src < dst {
        for offset in (0..length).rev() {
            set(items, dst + offset, at(items, src + offset).clone());
        }
    } else {
        for offset in 0..length {
            set(items, dst + offset, at(items, src + offset).clone());
        }
    }
}

fn copy_into<T: Clone>(source: &[T], src: isize, target: &mut [T], dst: isize, length: isize) {
    for offset in 0..length {
        set(target, dst + offset, at(source, src + offset).clone());
    }
}

/// Where `key` belongs in the sorted `items[base..base + length]`: before the leftmost element
/// equal to it. `hint` is where the search starts.
fn gallop_left<T, E>(
    less: &mut impl FnMut(&T, &T) -> Result<bool, E>,
    items: &[T],
    key: &T,
    base: isize,
    length: isize,
    hint: isize,
) -> Result<isize, E> {
    let mut last = 0;
    let mut offset = 1;
    if less(at(items, base + hint), key)? {
        let max = length - hint;
        while offset < max {
            if !less(at(items, base + hint + offset), key)? {
                break;
            }
            last = offset;
            offset = (offset << 1) + 1;
            if offset <= 0 {
                offset = max;
            }
        }
        offset = offset.min(max);
        last += hint;
        offset += hint;
    } else {
        let max = hint + 1;
        while offset < max {
            if less(at(items, base + hint - offset), key)? {
                break;
            }
            last = offset;
            offset = (offset << 1) + 1;
            if offset <= 0 {
                offset = max;
            }
        }
        offset = offset.min(max);
        (last, offset) = (hint - offset, hint - last);
    }
    last += 1;
    while last < offset {
        let middle = last + ((offset - last) >> 1);
        if less(at(items, base + middle), key)? {
            last = middle + 1;
        } else {
            offset = middle;
        }
    }
    Ok(offset)
}

/// Like [`gallop_left`], but after the rightmost element equal to `key`.
fn gallop_right<T, E>(
    less: &mut impl FnMut(&T, &T) -> Result<bool, E>,
    items: &[T],
    key: &T,
    base: isize,
    length: isize,
    hint: isize,
) -> Result<isize, E> {
    let mut last = 0;
    let mut offset = 1;
    if less(key, at(items, base + hint))? {
        let max = hint + 1;
        while offset < max {
            if !less(key, at(items, base + hint - offset))? {
                break;
            }
            last = offset;
            offset = (offset << 1) + 1;
            if offset <= 0 {
                offset = max;
            }
        }
        offset = offset.min(max);
        (last, offset) = (hint - offset, hint - last);
    } else {
        let max = length - hint;
        while offset < max {
            if less(key, at(items, base + hint + offset))? {
                break;
            }
            last = offset;
            offset = (offset << 1) + 1;
            if offset <= 0 {
                offset = max;
            }
        }
        offset = offset.min(max);
        last += hint;
        offset += hint;
    }
    last += 1;
    while last < offset {
        let middle = last + ((offset - last) >> 1);
        if less(key, at(items, base + middle))? {
            offset = middle;
        } else {
            last = middle + 1;
        }
    }
    Ok(offset)
}

enum Exit {
    Succeed,
    CopyRest,
}

impl<T: Clone, E, F: FnMut(&T, &T) -> Result<bool, E>> Sort<'_, T, F> {
    fn run(&mut self) -> Result<(), E> {
        let length = self.work.len() as isize;
        if length < 2 {
            return Ok(());
        }
        let mut remaining = length;
        let mut low = 0;
        let min_run = {
            let (mut n, mut r) = (remaining, 0);
            while n >= 64 {
                r |= n & 1;
                n >>= 1;
            }
            n + r
        };
        while remaining != 0 {
            let mut run = self.count_and_make_run(low, low + remaining)?;
            if run < min_run {
                let forced = min_run.min(remaining);
                self.binary_insertion_sort(low, low + run, low + forced)?;
                run = forced;
            }
            self.runs.push((low, run));
            self.merge_collapse()?;
            low += run;
            remaining -= run;
        }
        while self.runs.len() > 1 {
            let mut n = self.runs.len() - 2;
            if n > 0 && self.runs[n - 1].1 < self.runs[n + 1].1 {
                n -= 1;
            }
            self.merge_at(n)?;
        }
        Ok(())
    }

    /// Sorts `[low, high)` by binary insertion, given that `[low, start)` is sorted.
    fn binary_insertion_sort(&mut self, low: isize, start: isize, high: isize) -> Result<(), E> {
        let mut start = if low == start { start + 1 } else { start };
        while start < high {
            let (mut left, mut right) = (low, start);
            let pivot = at(self.work, right).clone();
            while left < right {
                let middle = left + ((right - left) >> 1);
                if (self.less)(&pivot, at(self.work, middle))? {
                    right = middle;
                } else {
                    left = middle + 1;
                }
            }
            let mut p = start;
            while p > left {
                set(self.work, p, at(self.work, p - 1).clone());
                p -= 1;
            }
            set(self.work, left, pivot);
            start += 1;
        }
        Ok(())
    }

    /// The length of the run starting at `low`: the longest nondescending or strictly
    /// descending sequence. A descending run is reversed in place.
    fn count_and_make_run(&mut self, low_start: isize, high: isize) -> Result<isize, E> {
        let low = low_start + 1;
        if low == high {
            return Ok(1);
        }
        let mut run = 2;
        let mut previous = at(self.work, low).clone();
        let descending = (self.less)(&previous, at(self.work, low - 1))?;
        for index in low + 1..high {
            let current = at(self.work, index).clone();
            let order_less = (self.less)(&current, &previous)?;
            if descending != order_less {
                break;
            }
            previous = current;
            run += 1;
        }
        if descending {
            let (start, end) = (
                usize::try_from(low_start).expect("sort index"),
                usize::try_from(low_start + run).expect("sort index"),
            );
            self.work[start..end].reverse();
        }
        Ok(run)
    }

    fn merge_collapse(&mut self) -> Result<(), E> {
        let established = |runs: &[(isize, isize)], n: usize| n < 2 || runs[n - 2].1 > runs[n - 1].1 + runs[n].1;
        while self.runs.len() > 1 {
            let mut n = self.runs.len() - 2;
            if !established(&self.runs, n + 1) || !established(&self.runs, n) {
                if self.runs[n - 1].1 < self.runs[n + 1].1 {
                    n -= 1;
                }
                self.merge_at(n)?;
            } else if self.runs[n].1 <= self.runs[n + 1].1 {
                self.merge_at(n)?;
            } else {
                break;
            }
        }
        Ok(())
    }

    /// Merges pending runs `i` and `i + 1`.
    fn merge_at(&mut self, i: usize) -> Result<(), E> {
        let (mut base_a, mut length_a) = self.runs[i];
        let (base_b, mut length_b) = self.runs[i + 1];
        self.runs[i].1 = length_a + length_b;
        if i + 3 == self.runs.len() {
            self.runs[i + 1] = self.runs[i + 2];
        }
        self.runs.pop();
        let key = at(self.work, base_b).clone();
        let k = gallop_right(&mut self.less, self.work, &key, base_a, length_a, 0)?;
        base_a += k;
        length_a -= k;
        if length_a == 0 {
            return Ok(());
        }
        let key = at(self.work, base_a + length_a - 1).clone();
        length_b = gallop_left(&mut self.less, self.work, &key, base_b, length_b, length_b - 1)?;
        if length_b == 0 {
            return Ok(());
        }
        if length_a <= length_b {
            self.merge_low(base_a, length_a, base_b, length_b)
        } else {
            self.merge_high(base_a, length_a, base_b, length_b)
        }
    }

    /// Merges two adjacent runs, `a` (the shorter) before `b`, copying `a` out first.
    fn merge_low(&mut self, base_a: isize, mut length_a: isize, base_b: isize, mut length_b: isize) -> Result<(), E> {
        let temp: Vec<T> = self.work[usize::try_from(base_a).expect("sort index")..][..length_a as usize].to_vec();
        let mut dest = base_a;
        let mut cursor_temp = 0;
        let mut cursor_b = base_b;
        set(self.work, dest, at(self.work, cursor_b).clone());
        dest += 1;
        cursor_b += 1;
        let exit = 'body: {
            length_b -= 1;
            if length_b == 0 {
                break 'body Exit::Succeed;
            }
            if length_a == 1 {
                break 'body Exit::CopyRest;
            }
            let mut min_gallop = self.min_gallop;
            loop {
                let mut wins_a = 0;
                let mut wins_b = 0;
                loop {
                    if (self.less)(at(self.work, cursor_b), at(&temp, cursor_temp))? {
                        set(self.work, dest, at(self.work, cursor_b).clone());
                        dest += 1;
                        cursor_b += 1;
                        wins_b += 1;
                        length_b -= 1;
                        wins_a = 0;
                        if length_b == 0 {
                            break 'body Exit::Succeed;
                        }
                        if wins_b >= min_gallop {
                            break;
                        }
                    } else {
                        set(self.work, dest, at(&temp, cursor_temp).clone());
                        dest += 1;
                        cursor_temp += 1;
                        wins_a += 1;
                        length_a -= 1;
                        wins_b = 0;
                        if length_a == 1 {
                            break 'body Exit::CopyRest;
                        }
                        if wins_a >= min_gallop {
                            break;
                        }
                    }
                }
                min_gallop += 1;
                let mut first = true;
                while wins_a >= MIN_GALLOP || wins_b >= MIN_GALLOP || first {
                    first = false;
                    min_gallop = (min_gallop - 1).max(1);
                    self.min_gallop = min_gallop;
                    let key = at(self.work, cursor_b).clone();
                    wins_a = gallop_right(&mut self.less, &temp, &key, cursor_temp, length_a, 0)?;
                    if wins_a > 0 {
                        copy_into(&temp, cursor_temp, self.work, dest, wins_a);
                        dest += wins_a;
                        cursor_temp += wins_a;
                        length_a -= wins_a;
                        if length_a == 1 {
                            break 'body Exit::CopyRest;
                        }
                        if length_a == 0 {
                            break 'body Exit::Succeed;
                        }
                    }
                    set(self.work, dest, at(self.work, cursor_b).clone());
                    dest += 1;
                    cursor_b += 1;
                    length_b -= 1;
                    if length_b == 0 {
                        break 'body Exit::Succeed;
                    }
                    let key = at(&temp, cursor_temp).clone();
                    wins_b = gallop_left(&mut self.less, self.work, &key, cursor_b, length_b, 0)?;
                    if wins_b > 0 {
                        move_within(self.work, cursor_b, dest, wins_b);
                        dest += wins_b;
                        cursor_b += wins_b;
                        length_b -= wins_b;
                        if length_b == 0 {
                            break 'body Exit::Succeed;
                        }
                    }
                    set(self.work, dest, at(&temp, cursor_temp).clone());
                    dest += 1;
                    cursor_temp += 1;
                    length_a -= 1;
                    if length_a == 1 {
                        break 'body Exit::CopyRest;
                    }
                }
                min_gallop += 1;
                self.min_gallop = min_gallop;
            }
        };
        match exit {
            Exit::Succeed => {
                if length_a > 0 {
                    copy_into(&temp, cursor_temp, self.work, dest, length_a);
                }
            }
            Exit::CopyRest => {
                move_within(self.work, cursor_b, dest, length_b);
                set(self.work, dest + length_b, at(&temp, cursor_temp).clone());
            }
        }
        Ok(())
    }

    /// Merges two adjacent runs, `a` before `b` (the shorter), copying `b` out and merging
    /// from the end.
    fn merge_high(&mut self, base_a: isize, mut length_a: isize, base_b: isize, mut length_b: isize) -> Result<(), E> {
        let temp: Vec<T> = self.work[usize::try_from(base_b).expect("sort index")..][..length_b as usize].to_vec();
        let mut dest = base_b + length_b - 1;
        let mut cursor_temp = length_b - 1;
        let mut cursor_a = base_a + length_a - 1;
        set(self.work, dest, at(self.work, cursor_a).clone());
        dest -= 1;
        cursor_a -= 1;
        let exit = 'body: {
            length_a -= 1;
            if length_a == 0 {
                break 'body Exit::Succeed;
            }
            if length_b == 1 {
                break 'body Exit::CopyRest;
            }
            let mut min_gallop = self.min_gallop;
            loop {
                let mut wins_a = 0;
                let mut wins_b = 0;
                loop {
                    if (self.less)(at(&temp, cursor_temp), at(self.work, cursor_a))? {
                        set(self.work, dest, at(self.work, cursor_a).clone());
                        dest -= 1;
                        cursor_a -= 1;
                        wins_a += 1;
                        length_a -= 1;
                        wins_b = 0;
                        if length_a == 0 {
                            break 'body Exit::Succeed;
                        }
                        if wins_a >= min_gallop {
                            break;
                        }
                    } else {
                        set(self.work, dest, at(&temp, cursor_temp).clone());
                        dest -= 1;
                        cursor_temp -= 1;
                        wins_b += 1;
                        length_b -= 1;
                        wins_a = 0;
                        if length_b == 1 {
                            break 'body Exit::CopyRest;
                        }
                        if wins_b >= min_gallop {
                            break;
                        }
                    }
                }
                min_gallop += 1;
                let mut first = true;
                while wins_a >= MIN_GALLOP || wins_b >= MIN_GALLOP || first {
                    first = false;
                    min_gallop = (min_gallop - 1).max(1);
                    self.min_gallop = min_gallop;
                    let key = at(&temp, cursor_temp).clone();
                    let k = gallop_right(&mut self.less, self.work, &key, base_a, length_a, length_a - 1)?;
                    wins_a = length_a - k;
                    if wins_a > 0 {
                        dest -= wins_a;
                        cursor_a -= wins_a;
                        move_within(self.work, cursor_a + 1, dest + 1, wins_a);
                        length_a -= wins_a;
                        if length_a == 0 {
                            break 'body Exit::Succeed;
                        }
                    }
                    set(self.work, dest, at(&temp, cursor_temp).clone());
                    dest -= 1;
                    cursor_temp -= 1;
                    length_b -= 1;
                    if length_b == 1 {
                        break 'body Exit::CopyRest;
                    }
                    let key = at(self.work, cursor_a).clone();
                    let k = gallop_left(&mut self.less, &temp, &key, 0, length_b, length_b - 1)?;
                    wins_b = length_b - k;
                    if wins_b > 0 {
                        dest -= wins_b;
                        cursor_temp -= wins_b;
                        copy_into(&temp, cursor_temp + 1, self.work, dest + 1, wins_b);
                        length_b -= wins_b;
                        if length_b == 1 {
                            break 'body Exit::CopyRest;
                        }
                        if length_b == 0 {
                            break 'body Exit::Succeed;
                        }
                    }
                    set(self.work, dest, at(self.work, cursor_a).clone());
                    dest -= 1;
                    cursor_a -= 1;
                    length_a -= 1;
                    if length_a == 0 {
                        break 'body Exit::Succeed;
                    }
                }
                min_gallop += 1;
                self.min_gallop = min_gallop;
            }
        };
        match exit {
            Exit::Succeed => {
                if length_b > 0 {
                    copy_into(&temp, 0, self.work, dest - (length_b - 1), length_b);
                }
            }
            Exit::CopyRest => {
                dest -= length_a;
                cursor_a -= length_a;
                move_within(self.work, cursor_a + 1, dest + 1, length_a);
                set(self.work, dest, at(&temp, cursor_temp).clone());
            }
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::timsort;

    /// Sorting by key with an index tiebreak checks stability over long runs that gallop.
    #[test]
    fn sorts_stably_through_galloping_merges() {
        let mut seed = 0x2545_f491_u64;
        for length in [0usize, 1, 2, 3, 31, 32, 33, 64, 65, 200, 1000, 5000] {
            let mut items: Vec<(u64, usize)> = (0..length)
                .map(|index| {
                    seed ^= seed << 13;
                    seed ^= seed >> 7;
                    seed ^= seed << 17;
                    // Few distinct keys and long presorted stretches exercise both galloping modes.
                    let key = if index % 97 < 50 { index as u64 / 3 } else { seed % 5 };
                    (key, index)
                })
                .collect();
            let mut expected = items.clone();
            expected.sort_by_key(|item| item.0);
            timsort::<_, ()>(&mut items, |x, y| Ok(x.0 < y.0)).unwrap();
            assert_eq!(items, expected, "length {length}");
        }
    }
}
