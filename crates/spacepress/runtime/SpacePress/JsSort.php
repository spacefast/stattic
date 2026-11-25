<?php

declare(strict_types=1);

namespace SpacePress;

/**
 * `Array.prototype.sort` as Node runs it. V8 (third_party/v8/builtins/array-sort.tq) sorts with
 * TimSort, ported from CPython's listsort. This is the same algorithm step for step, so a
 * comparator is called with the same pairs in the same order as under Node, and an inconsistent
 * or effectful comparator gives Node's result too. src/sort.rs carries the same port for
 * configuration evaluation; keep the two in step.
 *
 * `$less($x, $y)` answers ToNumber(compare(x, y)) < 0, with NaN counting as 0. The caller removes
 * undefined elements first and appends them afterwards.
 *
 * @internal
 */
final class JsSort
{
    private const MIN_GALLOP = 7;

    /** @var list<array{int, int}> pending runs as [base, length] */
    private array $runs = [];
    private int $minGallop = self::MIN_GALLOP;

    /** @param list<mixed> $work */
    private function __construct(private array $work, private readonly \Closure $less) {}

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    public static function sort(array $items, \Closure $less): array
    {
        $sort = new self($items, $less);
        $sort->run();
        return $sort->work;
    }

    private function run(): void
    {
        $length = count($this->work);
        if ($length < 2) { return; }
        $remaining = $length;
        $low = 0;
        $n = $remaining;
        $r = 0;
        while ($n >= 64) { $r |= $n & 1; $n >>= 1; }
        $minRun = $n + $r;
        while ($remaining !== 0) {
            $run = $this->countAndMakeRun($low, $low + $remaining);
            if ($run < $minRun) {
                $forced = min($minRun, $remaining);
                $this->binaryInsertionSort($low, $low + $run, $low + $forced);
                $run = $forced;
            }
            $this->runs[] = [$low, $run];
            $this->mergeCollapse();
            $low += $run;
            $remaining -= $run;
        }
        while (count($this->runs) > 1) {
            $n = count($this->runs) - 2;
            if ($n > 0 && $this->runs[$n - 1][1] < $this->runs[$n + 1][1]) { $n--; }
            $this->mergeAt($n);
        }
    }

    private function binaryInsertionSort(int $low, int $start, int $high): void
    {
        $less = $this->less;
        if ($low === $start) { $start++; }
        for (; $start < $high; $start++) {
            $left = $low;
            $right = $start;
            $pivot = $this->work[$right];
            while ($left < $right) {
                $middle = $left + (($right - $left) >> 1);
                if ($less($pivot, $this->work[$middle])) { $right = $middle; } else { $left = $middle + 1; }
            }
            for ($p = $start; $p > $left; $p--) { $this->work[$p] = $this->work[$p - 1]; }
            $this->work[$left] = $pivot;
        }
    }

    private function countAndMakeRun(int $lowStart, int $high): int
    {
        $less = $this->less;
        $low = $lowStart + 1;
        if ($low === $high) { return 1; }
        $run = 2;
        $previous = $this->work[$low];
        $descending = $less($previous, $this->work[$low - 1]);
        for ($index = $low + 1; $index < $high; $index++) {
            $current = $this->work[$index];
            if ($descending !== $less($current, $previous)) { break; }
            $previous = $current;
            $run++;
        }
        if ($descending) {
            for ($i = $lowStart, $j = $lowStart + $run - 1; $i < $j; $i++, $j--) {
                [$this->work[$i], $this->work[$j]] = [$this->work[$j], $this->work[$i]];
            }
        }
        return $run;
    }

    private function mergeCollapse(): void
    {
        $established = fn (int $n): bool => $n < 2 || $this->runs[$n - 2][1] > $this->runs[$n - 1][1] + $this->runs[$n][1];
        while (count($this->runs) > 1) {
            $n = count($this->runs) - 2;
            if (!$established($n + 1) || !$established($n)) {
                if ($this->runs[$n - 1][1] < $this->runs[$n + 1][1]) { $n--; }
                $this->mergeAt($n);
            } elseif ($this->runs[$n][1] <= $this->runs[$n + 1][1]) {
                $this->mergeAt($n);
            } else {
                break;
            }
        }
    }

    private function mergeAt(int $i): void
    {
        [$baseA, $lengthA] = $this->runs[$i];
        [$baseB, $lengthB] = $this->runs[$i + 1];
        $this->runs[$i][1] = $lengthA + $lengthB;
        if ($i + 3 === count($this->runs)) { $this->runs[$i + 1] = $this->runs[$i + 2]; }
        array_pop($this->runs);
        $k = $this->gallopRight($this->work, $this->work[$baseB], $baseA, $lengthA, 0);
        $baseA += $k;
        $lengthA -= $k;
        if ($lengthA === 0) { return; }
        $lengthB = $this->gallopLeft($this->work, $this->work[$baseA + $lengthA - 1], $baseB, $lengthB, $lengthB - 1);
        if ($lengthB === 0) { return; }
        if ($lengthA <= $lengthB) { $this->mergeLow($baseA, $lengthA, $baseB, $lengthB); }
        else { $this->mergeHigh($baseA, $lengthA, $baseB, $lengthB); }
    }

    /** @param list<mixed> $items where `$key` belongs: before the leftmost equal element */
    private function gallopLeft(array $items, mixed $key, int $base, int $length, int $hint): int
    {
        $less = $this->less;
        $last = 0;
        $offset = 1;
        if ($less($items[$base + $hint], $key)) {
            $max = $length - $hint;
            while ($offset < $max) {
                if (!$less($items[$base + $hint + $offset], $key)) { break; }
                $last = $offset;
                $offset = ($offset << 1) + 1;
            }
            $offset = min($offset, $max);
            $last += $hint;
            $offset += $hint;
        } else {
            $max = $hint + 1;
            while ($offset < $max) {
                if ($less($items[$base + $hint - $offset], $key)) { break; }
                $last = $offset;
                $offset = ($offset << 1) + 1;
            }
            $offset = min($offset, $max);
            [$last, $offset] = [$hint - $offset, $hint - $last];
        }
        $last++;
        while ($last < $offset) {
            $middle = $last + (($offset - $last) >> 1);
            if ($less($items[$base + $middle], $key)) { $last = $middle + 1; } else { $offset = $middle; }
        }
        return $offset;
    }

    /** @param list<mixed> $items where `$key` belongs: after the rightmost equal element */
    private function gallopRight(array $items, mixed $key, int $base, int $length, int $hint): int
    {
        $less = $this->less;
        $last = 0;
        $offset = 1;
        if ($less($key, $items[$base + $hint])) {
            $max = $hint + 1;
            while ($offset < $max) {
                if (!$less($key, $items[$base + $hint - $offset])) { break; }
                $last = $offset;
                $offset = ($offset << 1) + 1;
            }
            $offset = min($offset, $max);
            [$last, $offset] = [$hint - $offset, $hint - $last];
        } else {
            $max = $length - $hint;
            while ($offset < $max) {
                if ($less($key, $items[$base + $hint + $offset])) { break; }
                $last = $offset;
                $offset = ($offset << 1) + 1;
            }
            $offset = min($offset, $max);
            $last += $hint;
            $offset += $hint;
        }
        $last++;
        while ($last < $offset) {
            $middle = $last + (($offset - $last) >> 1);
            if ($less($key, $items[$base + $middle])) { $offset = $middle; } else { $last = $middle + 1; }
        }
        return $offset;
    }

    /** Copies `$length` elements within the work array, from the back when ranges overlap that way. */
    private function move(int $src, int $dst, int $length): void
    {
        if ($src < $dst) {
            for ($o = $length - 1; $o >= 0; $o--) { $this->work[$dst + $o] = $this->work[$src + $o]; }
        } else {
            for ($o = 0; $o < $length; $o++) { $this->work[$dst + $o] = $this->work[$src + $o]; }
        }
    }

    /** @param list<mixed> $temp */
    private function copyIn(array $temp, int $src, int $dst, int $length): void
    {
        for ($o = 0; $o < $length; $o++) { $this->work[$dst + $o] = $temp[$src + $o]; }
    }

    /** Merges adjacent runs, `a` (the shorter) before `b`, copying `a` out first. */
    private function mergeLow(int $baseA, int $lengthA, int $baseB, int $lengthB): void
    {
        $less = $this->less;
        $temp = array_slice($this->work, $baseA, $lengthA);
        $dest = $baseA;
        $cursorTemp = 0;
        $cursorB = $baseB;
        $this->work[$dest++] = $this->work[$cursorB++];
        $exit = (function () use (&$lengthA, &$lengthB, &$dest, &$cursorTemp, &$cursorB, $temp, $less): bool {
            if (--$lengthB === 0) { return true; }
            if ($lengthA === 1) { return false; }
            $minGallop = $this->minGallop;
            while (true) {
                $winsA = 0;
                $winsB = 0;
                while (true) {
                    if ($less($this->work[$cursorB], $temp[$cursorTemp])) {
                        $this->work[$dest++] = $this->work[$cursorB++];
                        $winsB++;
                        $lengthB--;
                        $winsA = 0;
                        if ($lengthB === 0) { return true; }
                        if ($winsB >= $minGallop) { break; }
                    } else {
                        $this->work[$dest++] = $temp[$cursorTemp++];
                        $winsA++;
                        $lengthA--;
                        $winsB = 0;
                        if ($lengthA === 1) { return false; }
                        if ($winsA >= $minGallop) { break; }
                    }
                }
                $minGallop++;
                $first = true;
                while ($winsA >= self::MIN_GALLOP || $winsB >= self::MIN_GALLOP || $first) {
                    $first = false;
                    $minGallop = max(1, $minGallop - 1);
                    $this->minGallop = $minGallop;
                    $winsA = $this->gallopRight($temp, $this->work[$cursorB], $cursorTemp, $lengthA, 0);
                    if ($winsA > 0) {
                        $this->copyIn($temp, $cursorTemp, $dest, $winsA);
                        $dest += $winsA;
                        $cursorTemp += $winsA;
                        $lengthA -= $winsA;
                        if ($lengthA === 1) { return false; }
                        if ($lengthA === 0) { return true; }
                    }
                    $this->work[$dest++] = $this->work[$cursorB++];
                    if (--$lengthB === 0) { return true; }
                    $winsB = $this->gallopLeft($this->work, $temp[$cursorTemp], $cursorB, $lengthB, 0);
                    if ($winsB > 0) {
                        $this->move($cursorB, $dest, $winsB);
                        $dest += $winsB;
                        $cursorB += $winsB;
                        $lengthB -= $winsB;
                        if ($lengthB === 0) { return true; }
                    }
                    $this->work[$dest++] = $temp[$cursorTemp++];
                    if (--$lengthA === 1) { return false; }
                }
                $minGallop++;
                $this->minGallop = $minGallop;
            }
        })();
        if ($exit) {
            if ($lengthA > 0) { $this->copyIn($temp, $cursorTemp, $dest, $lengthA); }
        } else {
            $this->move($cursorB, $dest, $lengthB);
            $this->work[$dest + $lengthB] = $temp[$cursorTemp];
        }
    }

    /** Merges adjacent runs, `a` before `b` (the shorter), copying `b` out and merging from the end. */
    private function mergeHigh(int $baseA, int $lengthA, int $baseB, int $lengthB): void
    {
        $less = $this->less;
        $temp = array_slice($this->work, $baseB, $lengthB);
        $dest = $baseB + $lengthB - 1;
        $cursorTemp = $lengthB - 1;
        $cursorA = $baseA + $lengthA - 1;
        $this->work[$dest--] = $this->work[$cursorA--];
        $exit = (function () use (&$lengthA, &$lengthB, &$dest, &$cursorTemp, &$cursorA, $temp, $baseA, $less): bool {
            if (--$lengthA === 0) { return true; }
            if ($lengthB === 1) { return false; }
            $minGallop = $this->minGallop;
            while (true) {
                $winsA = 0;
                $winsB = 0;
                while (true) {
                    if ($less($temp[$cursorTemp], $this->work[$cursorA])) {
                        $this->work[$dest--] = $this->work[$cursorA--];
                        $winsA++;
                        $lengthA--;
                        $winsB = 0;
                        if ($lengthA === 0) { return true; }
                        if ($winsA >= $minGallop) { break; }
                    } else {
                        $this->work[$dest--] = $temp[$cursorTemp--];
                        $winsB++;
                        $lengthB--;
                        $winsA = 0;
                        if ($lengthB === 1) { return false; }
                        if ($winsB >= $minGallop) { break; }
                    }
                }
                $minGallop++;
                $first = true;
                while ($winsA >= self::MIN_GALLOP || $winsB >= self::MIN_GALLOP || $first) {
                    $first = false;
                    $minGallop = max(1, $minGallop - 1);
                    $this->minGallop = $minGallop;
                    $k = $this->gallopRight($this->work, $temp[$cursorTemp], $baseA, $lengthA, $lengthA - 1);
                    $winsA = $lengthA - $k;
                    if ($winsA > 0) {
                        $dest -= $winsA;
                        $cursorA -= $winsA;
                        $this->move($cursorA + 1, $dest + 1, $winsA);
                        $lengthA -= $winsA;
                        if ($lengthA === 0) { return true; }
                    }
                    $this->work[$dest--] = $temp[$cursorTemp--];
                    if (--$lengthB === 1) { return false; }
                    $k = $this->gallopLeft($temp, $this->work[$cursorA], 0, $lengthB, $lengthB - 1);
                    $winsB = $lengthB - $k;
                    if ($winsB > 0) {
                        $dest -= $winsB;
                        $cursorTemp -= $winsB;
                        $this->copyIn($temp, $cursorTemp + 1, $dest + 1, $winsB);
                        $lengthB -= $winsB;
                        if ($lengthB === 1) { return false; }
                        if ($lengthB === 0) { return true; }
                    }
                    $this->work[$dest--] = $this->work[$cursorA--];
                    if (--$lengthA === 0) { return true; }
                }
                $minGallop++;
                $this->minGallop = $minGallop;
            }
        })();
        if ($exit) {
            if ($lengthB > 0) { $this->copyIn($temp, 0, $dest - ($lengthB - 1), $lengthB); }
        } else {
            $dest -= $lengthA;
            $cursorA -= $lengthA;
            $this->move($cursorA + 1, $dest + 1, $lengthA);
            $this->work[$dest] = $temp[$cursorTemp];
        }
    }
}
