//! Whitespace layout for compiler-owned PHP. The emitter produces pure PHP with quoted
//! strings (never heredocs or inline HTML). Keep literals and comments byte-for-byte;
//! this pass lays out parentheses and spells array literals as `array(...)`. Indexing
//! retains square brackets. The compiler lowers destructuring to explicit assignments.

fn tokenize(source: &str) -> Vec<std::ops::Range<usize>> {
    let bytes = source.as_bytes();
    let mut tokens = Vec::new();
    let mut i = 0;
    while i < bytes.len() {
        let start = i;
        match bytes[i] {
            b'\'' | b'"' | b'`' => {
                let quote = bytes[i];
                i += 1;
                while i < bytes.len() {
                    if bytes[i] == b'\\' {
                        i = (i + 2).min(bytes.len());
                    } else if bytes[i] == quote {
                        i += 1;
                        break;
                    } else {
                        i += 1;
                    }
                }
            }
            b'/' if bytes.get(i + 1) == Some(&b'*') => {
                i += 2;
                while i < bytes.len() && !bytes[i..].starts_with(b"*/") {
                    i += 1;
                }
                i = (i + 2).min(bytes.len());
            }
            b'/' if bytes.get(i + 1) == Some(&b'/') => {
                while i < bytes.len() && bytes[i] != b'\n' {
                    i += 1;
                }
            }
            b'#' if bytes.get(i + 1) != Some(&b'[') => {
                while i < bytes.len() && bytes[i] != b'\n' {
                    i += 1;
                }
            }
            b'!' if bytes.get(i + 1) == Some(&b'=') => {
                i += if bytes.get(i + 2) == Some(&b'=') { 3 } else { 2 };
            }
            b'$' => {
                i += 1;
                while i < bytes.len() && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'_' || bytes[i] >= 128) {
                    i += 1;
                }
            }
            b'.' if bytes[i..].starts_with(b"...") => i += 3,
            c if c.is_ascii_whitespace() => {
                i += 1;
                continue;
            }
            c if c.is_ascii_alphanumeric() || c == b'_' => {
                i += 1;
                while i < bytes.len() && (bytes[i].is_ascii_alphanumeric() || bytes[i] == b'_') {
                    i += 1;
                }
            }
            _ => i += source[i..].chars().next().expect("character").len_utf8(),
        }
        tokens.push(start..i);
    }
    tokens
}

pub(crate) fn format(source: &str) -> String {
    let tokens = tokenize(source);
    let value = |n: usize| &source[tokens[n].clone()];
    let mut cast = vec![false; tokens.len()];
    for n in 0..tokens.len().saturating_sub(2) {
        if value(n) == "("
            && value(n + 2) == ")"
            && matches!(
                value(n + 1),
                "int" | "float" | "bool" | "string" | "array" | "object" | "unset"
            )
        {
            cast[n] = true;
            cast[n + 2] = true;
        }
    }
    // A literal starts where an expression may begin. A bracket following a value,
    // variable, property, call, or another closing bracket is an index instead.
    let mut arrays = vec![false; tokens.len()];
    let mut brackets = Vec::new();
    for (n, is_array) in arrays.iter_mut().enumerate() {
        match value(n) {
            "[" => {
                let literal = n == 0
                    || (n < 2 || !matches!(value(n - 2), ">" | ":"))
                        && matches!(
                            value(n - 1),
                            "(" | "["
                                | "..."
                                | ","
                                | "="
                                | ":"
                                | "?"
                                | ">"
                                | "<"
                                | "+"
                                | "-"
                                | "*"
                                | "/"
                                | "%"
                                | "!"
                                | "&"
                                | "|"
                                | "^"
                                | "~"
                                | "return"
                                | "yield"
                                | "case"
                                | "echo"
                                | "throw"
                                | "!="
                                | "!=="
                        );
                *is_array = literal;
                brackets.push(literal);
            }
            "]" => *is_array = brackets.pop().unwrap_or(false),
            _ => {}
        }
    }
    let mut indexes = vec![false; tokens.len()];
    let mut openings = Vec::new();
    for n in 0..tokens.len() {
        match value(n) {
            "[" => openings.push(n),
            "]" => {
                let open = openings.pop().expect("emitted brackets match");
                let first = if matches!(value(open + 1), "+" | "-") {
                    open + 2
                } else {
                    open + 1
                };
                let simple = n == open + 1
                    || (n == first + 1
                        && (constant_string(value(first)) || value(first).bytes().all(|b| b.is_ascii_digit())));
                if !arrays[open] && !simple && (open == 0 || value(open - 1) != "#") {
                    indexes[open] = true;
                    indexes[n] = true;
                }
            }
            _ => {}
        }
    }
    let layout_token = |n: usize| match (value(n), arrays[n]) {
        ("[", true) => "(",
        ("]", true) => ")",
        _ => value(n),
    };
    let mut out = String::with_capacity(source.len());
    let mut end = 0;
    for (n, token) in tokens.iter().enumerate() {
        let gap = &source[end..token.start];
        if n > 0 && !gap.contains(['\n', '\r']) {
            let left = layout_token(n - 1);
            let right = layout_token(n);
            if left == "(" && right == ")" {
                // Empty argument/parameter lists stay empty.
            } else if (left == "(" && !cast[n - 1])
                || (right == ")" && !cast[n])
                || (left == "[" && indexes[n - 1])
                || (right == "]" && indexes[n])
                || left == "!"
                || right == "!"
            {
                out.push(' ');
            } else {
                out.push_str(gap);
            }
        } else {
            out.push_str(gap);
        }
        match (value(n), arrays[n]) {
            ("[", true) => out.push_str("array("),
            ("]", true) => out.push(')'),
            _ => out.push_str(&source[token.clone()]),
        }
        end = token.end;
    }
    out.push_str(&source[end..]);
    while out.ends_with("\n\n") {
        out.pop();
    }
    let out = multiline_arrays(&out);
    align_assignments(&out)
}

fn constant_string(value: &str) -> bool {
    if value.starts_with('\'') {
        return true;
    }
    if !value.starts_with('"') {
        return false;
    }
    let mut bytes = value.bytes();
    while let Some(byte) = bytes.next() {
        if byte == b'\\' {
            bytes.next();
        } else if byte == b'$' {
            return false;
        }
    }
    true
}

// Find the assignment after a variable and its property/index path, without
// mistaking an assignment inside a call or key expression for the outer one.
fn assignment_operator(source: &str, tokens: &[std::ops::Range<usize>], start: usize) -> Option<usize> {
    let value = |n: usize| tokens.get(n).map(|t| &source[t.clone()]);
    let mut n = start + 1;
    loop {
        match value(n)? {
            "=" if !matches!(value(n + 1), Some("=" | ">")) => return Some(n),
            "-" if value(n + 1) == Some(">") => {
                let property = value(n + 2)?;
                if !property.chars().all(|c| c.is_alphanumeric() || matches!(c, '_' | '$')) {
                    return None;
                }
                n += 3;
            }
            "[" => {
                let mut depth = 1;
                n += 1;
                while depth > 0 {
                    match value(n)? {
                        "[" => depth += 1,
                        "]" => depth -= 1,
                        _ => {}
                    }
                    n += 1;
                }
            }
            _ => return None,
        }
    }
}

// Align assignments at the start of consecutive, equally indented lines.
// Token boundaries keep literal/comment contents opaque.
fn assignment_gaps(source: &str, tokens: &[std::ops::Range<usize>]) -> Vec<usize> {
    let mut candidates = Vec::new();
    let mut line = 0;
    let mut line_start = 0;
    let mut cursor = 0;
    for (n, token) in tokens.iter().enumerate() {
        for (offset, byte) in source.as_bytes()[cursor..token.start].iter().enumerate() {
            if *byte == b'\n' {
                line += 1;
                line_start = cursor + offset + 1;
            }
        }
        cursor = token.start;
        let indent = &source[line_start..token.start];
        if source[token.clone()].starts_with('$')
            && indent.bytes().all(|b| b == b'\t' || b == b' ')
            && let Some(equal) = assignment_operator(source, tokens, n)
            && !source[token.end..tokens[equal].start].contains('\n')
        {
            let mut depth = 0;
            let last = tokens.iter().skip(equal + 1).find(|t| {
                match &source[(*t).clone()] {
                    "(" | "[" | "{" => depth += 1,
                    ")" | "]" | "}" => depth -= 1,
                    ";" if depth == 0 => return true,
                    _ => {}
                }
                false
            });
            let end_line = last.map_or(line, |t| {
                line + source[token.start..t.end].bytes().filter(|b| *b == b'\n').count()
            });
            let width = source[token.start..tokens[equal].start].trim_end().chars().count();
            candidates.push((line, indent, equal, width, end_line));
        }
    }
    let mut gaps = vec![0; tokens.len()];
    let mut start = 0;
    while start < candidates.len() {
        let mut end = start + 1;
        while end < candidates.len()
            && candidates[end].0 == candidates[end - 1].4 + 1
            && candidates[end].1 == candidates[start].1
        {
            end += 1;
        }
        let width = candidates[start..end].iter().map(|c| c.3).max().unwrap();
        for (_, _, token, len, _) in &candidates[start..end] {
            gaps[*token] = width - len + 1;
        }
        start = end;
    }
    gaps
}

// Array layout can introduce line breaks into enclosing calls. Work on the
// already-spelled PHP so short and long array input follow the same path.
fn multiline_arrays(source: &str) -> String {
    struct Group {
        open: usize,
        close: usize,
        parent: Option<usize>,
        array: bool,
        commas: Vec<usize>,
        arrows: Vec<usize>,
        multiline: bool,
    }
    let tokens = tokenize(source);
    let value = |n: usize| &source[tokens[n].clone()];
    let mut groups: Vec<Group> = Vec::new();
    let mut stack: Vec<usize> = Vec::new();
    let mut closed = vec![None; tokens.len()];
    for n in 0..tokens.len() {
        match value(n) {
            "(" | "[" | "{" => {
                groups.push(Group {
                    open: n,
                    close: n,
                    parent: stack.last().copied(),
                    array: value(n) == "(" && n > 0 && value(n - 1) == "array",
                    commas: Vec::new(),
                    arrows: Vec::new(),
                    multiline: false,
                });
                stack.push(groups.len() - 1);
            }
            ")" | "]" | "}" => {
                let group = stack.pop().expect("emitted delimiters match");
                groups[group].close = n;
                closed[n] = Some(group);
            }
            "," => {
                if let Some(group) = stack.last() {
                    groups[*group].commas.push(n);
                }
            }
            "=" if n + 1 < tokens.len() && value(n + 1) == ">" => {
                if n > 0 && closed[n - 1].is_some_and(|g| groups[g].open > 0 && value(groups[g].open - 1) == "fn") {
                    continue;
                }
                if let Some(group) = stack.last() {
                    groups[*group].arrows.push(n);
                }
            }
            _ => {}
        }
    }
    for i in 0..groups.len() {
        if groups[i].array && !groups[i].arrows.is_empty() && groups[i].commas.iter().any(|n| *n + 1 < groups[i].close)
        {
            let mut group = Some(i);
            while let Some(n) = group {
                if value(groups[n].open) != "(" {
                    break;
                }
                groups[n].multiline = true;
                group = groups[n].parent;
            }
        }
    }
    let mut gaps: Vec<Option<String>> = vec![None; tokens.len()];
    let mut commas = vec![false; tokens.len()];
    for group in groups.iter().filter(|g| g.multiline) {
        let mut root = group;
        let mut depth = 0;
        while let Some(parent) = root.parent.filter(|p| groups[*p].multiline) {
            root = &groups[parent];
            depth += 1;
        }
        let line_start = source[..tokens[root.open].start].rfind('\n').map_or(0, |n| n + 1);
        let base: String = source[line_start..tokens[root.open].start]
            .chars()
            .take_while(|c| matches!(c, ' ' | '\t'))
            .collect();
        let indent = base + &"\t".repeat(depth);
        let inner = format!("{indent}\t");
        gaps[group.open + 1] = Some(format!("\n{inner}"));
        gaps[group.close] = Some(format!("\n{indent}"));
        for comma in &group.commas {
            if comma + 1 < group.close {
                let mut next = comma + 1;
                while next < group.close && ["//", "/*", "#"].iter().any(|p| value(next).starts_with(p)) {
                    next += 1;
                }
                if next < group.close {
                    gaps[next] = Some(format!("\n{inner}"));
                }
            }
        }
        if group.array {
            let last = (group.open + 1..group.close)
                .rev()
                .find(|n| !["//", "/*", "#"].iter().any(|prefix| value(*n).starts_with(prefix)))
                .expect("multiline array has items");
            commas[last] = value(last) != ",";
            let mut start = group.open + 1;
            let mut separator = 0;
            let mut keys = Vec::new();
            for arrow in &group.arrows {
                while separator < group.commas.len() && group.commas[separator] < *arrow {
                    start = group.commas[separator] + 1;
                    separator += 1;
                }
                while start < *arrow && ["//", "/*", "#"].iter().any(|prefix| value(start).starts_with(prefix)) {
                    start += 1;
                }
                let width = source[tokens[start].start..tokens[*arrow].start]
                    .trim_end()
                    .chars()
                    .count();
                keys.push((*arrow, width));
            }
            if let Some(width) = keys.iter().map(|(_, w)| *w).max() {
                for (arrow, len) in keys {
                    let original = &source[tokens[arrow - 1].end..tokens[arrow].start];
                    if !original.contains('\n') {
                        gaps[arrow] = Some(" ".repeat(width - len + 1));
                    }
                }
            }
        }
    }
    let mut out = String::with_capacity(source.len());
    let mut end = 0;
    for (n, token) in tokens.iter().enumerate() {
        out.push_str(gaps[n].as_deref().unwrap_or(&source[end..token.start]));
        out.push_str(&source[token.clone()]);
        if commas[n] {
            out.push(',');
        }
        end = token.end;
    }
    out.push_str(&source[end..]);
    out
}

fn align_assignments(source: &str) -> String {
    let tokens = tokenize(source);
    let gaps = assignment_gaps(source, &tokens);
    let mut out = String::with_capacity(source.len());
    let mut end = 0;
    for (n, token) in tokens.iter().enumerate() {
        if gaps[n] > 0 {
            out.extend(std::iter::repeat_n(' ', gaps[n]));
        } else {
            out.push_str(&source[end..token.start]);
        }
        out.push_str(&source[token.clone()]);
        end = token.end;
    }
    out.push_str(&source[end..]);
    out
}

#[cfg(test)]
mod tests {
    use super::format;

    #[test]
    fn formats_nested_calls_declarations_and_conditions_but_keeps_casts_and_empty_lists() {
        let source = "function f($x) { if (($x > 0)) { return foo((int)$x, bar(), []); } }";
        let expected = "function f( $x ) { if ( ( $x > 0 ) ) { return foo( (int)$x, bar(), array() ); } }";
        assert_eq!(format(source), expected);
        assert_eq!(format(expected), expected);
        assert_eq!(format("$żółć = f($x);"), "$żółć = f( $x );");
    }

    #[test]
    fn keeps_literals_comments_unicode_and_multiline_indentation() {
        let source = "foo('a\\\' (x)', \"żółć ($x) \\\"\", `echo (x)`); // (comment)\n/* (x) */\n# (x)\nfoo(\n\t$x\n);";
        let expected =
            "foo( 'a\\\' (x)', \"żółć ($x) \\\"\", `echo (x)` ); // (comment)\n/* (x) */\n# (x)\nfoo(\n\t$x\n);";
        assert_eq!(format(source), expected);
    }
}

#[cfg(test)]
mod array_tests {
    #[test]
    fn literals_become_long_arrays_but_indexes_and_strings_stay_intact() {
        let source = "return [[], ['k' => $a[0]], foo()[1], ['x'][0], $a[$i + 1], '[x]'];";
        let expected =
            "return array( array(), array( 'k' => $a[0] ), foo()[1], array( 'x' )[0], $a[ $i + 1 ], '[x]' );";
        assert_eq!(super::format(source), expected);
        assert_eq!(super::format(expected), expected);
        assert_eq!(super::format("foo(...[1]);"), "foo( ...array( 1 ) );");
        assert_eq!(
            super::format("return [$echo[0], $żółć[1], $x->echo[0], C::echo[0]];"),
            "return array( $echo[0], $żółć[1], $x->echo[0], C::echo[0] );"
        );
    }
}

#[cfg(test)]
mod mechanical_tests {
    #[test]
    fn aligns_variable_property_and_index_assignments_together() {
        let output = super::format("$a = 1;\n$object->p[$key] = 2;\n$array[0] = 3;\n");
        let columns: Vec<_> = output.lines().map(|line| line.find('=').unwrap()).collect();
        assert!(columns.iter().all(|column| *column == columns[0]), "{output}");
        assert_eq!(super::format(&output), output);
        let call = "$f($value = 1);\n$object->method($other = 2);\n";
        assert_eq!(
            super::format(call),
            "$f( $value = 1 );\n$object->method( $other = 2 );\n"
        );
    }

    #[test]
    fn aligns_assignment_groups_and_spaces_negation_and_computed_keys() {
        let source = "$x = 1;\n$long_name = '!';\n\n$x = !$long_name;\n$z = $items[$i + 1];\n\n";
        let expected = "$x         = 1;\n$long_name = '!';\n\n$x = ! $long_name;\n$z = $items[ $i + 1 ];\n";
        assert_eq!(super::format(source), expected);
        assert_eq!(super::format(expected), expected);
        assert_eq!(
            super::format("return $x != 1 && $y !== 2;"),
            "return $x != 1 && $y !== 2;"
        );
    }

    #[test]
    fn never_aligns_or_rewrites_statements_inside_literals_or_comments() {
        let source = "/*\n$a = !$x;\n$long = $items[$i];\n*/\nreturn '$a = !$x;\n$long = $items[$i];';";
        assert_eq!(super::format(source), source);
        assert_eq!(
            super::format("$a[0] = $a['k']; $a[] = $a[\"k\"];"),
            "$a[0] = $a['k']; $a[] = $a[\"k\"];"
        );
    }
}

#[cfg(test)]
mod multiline_tests {
    #[test]
    fn comments_after_array_commas_do_not_widen_the_following_key() {
        for source in [
            "return ['a' => 1, // keep\n 'b' => 2];",
            "return ['a' => 1, /* keep */ 'b' => 2];",
            "return ['a' => 1, # keep\n 'b' => 2];",
        ] {
            let output = super::format(source);
            let columns: Vec<_> = output.lines().filter_map(|line| line.find("=>")).collect();
            assert_eq!(columns.len(), 2, "{output}");
            assert_eq!(columns[0], columns[1], "{output}");
            assert_eq!(super::format(&output), output);
        }
    }

    #[test]
    fn a_line_comment_before_an_array_arrow_keeps_its_terminating_newline() {
        let output = super::format("return ['a' // key\n => 1, 'b' => 2];");
        assert!(output.contains("// key\n"), "{output}");
        let tokens = super::tokenize(&output);
        let arrows = tokens
            .windows(2)
            .filter(|pair| &output[pair[0].clone()] == "=" && &output[pair[1].clone()] == ">")
            .count();
        assert_eq!(arrows, 2, "an array item was swallowed by a line comment: {output}");
        assert_eq!(super::format(&output), output);
    }

    #[test]
    fn reflows_nested_arrays_and_calls_with_aligned_keys_and_optional_commas() {
        let source = "\treturn f(['a' => 1, 'long' => g(['x' => 2, 'y' => 3])]);\n";
        let output = super::format(source);
        assert!(output.contains("f(\n\t\tarray("), "{output}");
        assert!(output.contains("'a'    => 1,\n"), "{output}");
        assert!(output.contains("'y' => 3,\n"), "{output}");
        assert_eq!(super::format(&output), output);
    }

    #[test]
    fn keeps_callback_arrows_out_of_key_alignment_and_comments_out_of_values() {
        assert_eq!(
            super::format("return [fn($x) => $x, fn($y) => $y];"),
            "return array( fn( $x ) => $x, fn( $y ) => $y );"
        );
        let source = "return ['a' => fn($x) => $x, 'long' => 2 /* keep [x] */];";
        let output = super::format(source);
        assert!(output.contains("'a'    => fn( $x ) => $x,"), "{output}");
        assert!(output.contains("2, /* keep [x] */"), "{output}");
        assert_eq!(super::format(&output), output);
        let output = super::format("return ['a' => 1, // keep\n 'b' => 2 // last\n];");
        assert!(output.contains("1, // keep"), "{output}");
        assert!(output.contains("2, // last"), "{output}");
        assert_eq!(super::format(&output), output);
    }
}
