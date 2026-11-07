<?php

declare(strict_types=1);

namespace Zero\Sync;

use Zero\Vendor\WordPress\Merge\Diff\LineDiffer;
use Zero\Vendor\WordPress\Merge\Merge\LineMerger;
use Zero\Vendor\WordPress\Merge\Merge\MergeConflict;
use Zero\Vendor\WordPress\Merge\MergeStrategy;

/** Three-way body merge by line on the bundled php-toolkit, so the CLI and the runtime share one merger. */
final class Merge
{
    /** @return array{merged: ?string, conflicts: list<array{ours: string, theirs: string, line: int}>} */
    public static function bodies(string $base, string $ours, string $theirs): array
    {
        if ($ours === $theirs || $theirs === $base) {
            return ['merged' => $ours, 'conflicts' => []];
        }
        if ($ours === $base) {
            return ['merged' => $theirs, 'conflicts' => []];
        }
        $result = (new MergeStrategy(new LineDiffer(), new LineMerger()))->merge($base, $ours, $theirs);
        if (!$result->has_conflicts()) {
            // LineDiffer terminates every split line, so the merge gains one trailing newline.
            $merged = $result->get_merged_content();

            return ['merged' => str_ends_with($merged, "\n") ? substr($merged, 0, -1) : $merged, 'conflicts' => []];
        }
        $conflicts = [];
        $line = 1;
        foreach ((new \ReflectionProperty($result, 'results'))->getValue($result) as $part) {
            if ($part instanceof MergeConflict) {
                $conflicts[] = ['ours' => $part->ours, 'theirs' => $part->theirs, 'line' => $line];
                $line += substr_count($part->ours, "\n");
            } else {
                $line += substr_count((string) $part, "\n");
            }
        }

        return ['merged' => null, 'conflicts' => $conflicts];
    }
}
