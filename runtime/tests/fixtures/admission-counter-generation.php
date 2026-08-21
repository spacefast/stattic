<?php
declare(strict_types=1);

require_once __DIR__ . '/../../engine/shared/admission.php';

function admission_fixture_file_counter_path(string $path): string
{
    $pointerPath = $path . '.generation';
    if (!is_file($pointerPath)) {
        return $path;
    }
    $pointer = json_decode((string) file_get_contents($pointerPath), true, flags: JSON_THROW_ON_ERROR);
    $generation = is_string($pointer['generation'] ?? null) ? $pointer['generation'] : '';
    return $path . '.' . $generation;
}

function admission_fixture_counter_value(string $path): ?int
{
    $counter = json_decode((string) file_get_contents(admission_fixture_file_counter_path($path)), true, flags: JSON_THROW_ON_ERROR);
    return is_int($counter['count'] ?? null) ? $counter['count'] : null;
}

function admission_fixture_generation_file_count(string $path): int
{
    $matches = glob($path . '.*');
    if ($matches === false) {
        return 0;
    }
    return count(array_filter(
        $matches,
        static fn (string $candidate): bool => preg_match('/\.[a-f0-9]{32}$/', $candidate) === 1,
    ));
}

function admission_fixture_legacy_file_acquire(string $path, int $limit): callable|false
{
    $handle = fopen($path, 'c+');
    if (!is_resource($handle)) {
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    $raw = stream_get_contents($handle);
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? max(0, $decoded['count']) : 0;
    if ($count >= $limit) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(['count' => $count + 1, 'updated_at' => time()], JSON_THROW_ON_ERROR) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return static function () use ($path): void {
        $handle = fopen($path, 'c+');
        if (!is_resource($handle)) {
            return;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }
        $raw = stream_get_contents($handle);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $count = is_array($decoded) && is_int($decoded['count'] ?? null) ? max(0, $decoded['count'] - 1) : 0;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['count' => $count, 'updated_at' => time()], JSON_THROW_ON_ERROR) . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    };
}

function admission_fixture_mixed_file_cutover(string $privateRoot, int $limit): array
{
    $path = $privateRoot . '/runtime/admission/legacy-counter.json';

    // Old-code requests fill the legacy counter to the limit and are still in
    // flight (non-stale) when the first new-code request cuts over during a
    // deploy — the worst case for the cutover policy.
    $legacyReleases = [];
    for ($holder = 0; $holder < $limit; $holder++) {
        $release = admission_fixture_legacy_file_acquire($path, $limit);
        if (!is_callable($release)) {
            throw new RuntimeException('legacy holder was not admitted');
        }
        $legacyReleases[] = $release;
    }

    // The cutover abandons the live legacy count rather than carrying it:
    // those holders release into the legacy path, never the generation file,
    // so a carried count would shed valid requests as phantom slots until the
    // stale window. The first new-code request must therefore be admitted.
    $releaseB = _stattic_admission_counter_acquire($path, $limit, 60);
    if (!is_callable($releaseB)) {
        throw new RuntimeException('current request B was shed by abandoned legacy slots at cutover');
    }
    $legacyPathExistsAfterCutover = is_file($path);
    $countAfterCutover = admission_fixture_counter_value($path);

    // Old-code holders finish: their releases recreate/decrement only the
    // legacy path and must not touch the new generation's count.
    foreach ($legacyReleases as $release) {
        $release();
    }
    $countAfterLegacyRelease = admission_fixture_counter_value($path);

    $freshResults = [];
    $freshReleases = [];
    for ($attempt = 0; $attempt < $limit; $attempt++) {
        $release = _stattic_admission_counter_acquire($path, $limit, 60);
        $freshResults[] = is_callable($release);
        if (is_callable($release)) {
            $freshReleases[] = $release;
        }
    }
    $persistedCountAtLimit = admission_fixture_counter_value($path);

    foreach ($freshReleases as $release) {
        $release();
    }
    $releaseB();

    return [
        'request_b_admitted' => is_callable($releaseB),
        'legacy_path_exists_after_cutover' => $legacyPathExistsAfterCutover,
        'count_after_cutover' => $countAfterCutover,
        'count_after_legacy_release' => $countAfterLegacyRelease,
        'fresh_results' => $freshResults,
        'persisted_count_at_limit' => $persistedCountAtLimit,
        'final_persisted_count' => admission_fixture_counter_value($path),
    ];
}

$limit = 2;
$fixtureRoot = sys_get_temp_dir() . '/spacefast-admission-generation-' . bin2hex(random_bytes(8));
$privateRoot = $fixtureRoot . '/.stattic/storage';
mkdir($privateRoot, 0775, true);
// Realpath AFTER mkdir: on macOS sys_get_temp_dir() is /var/folders/…, a
// symlink to /private/var/…. The engine's private-path assert compares a
// textually-normalized candidate against the realpath'd root, so an
// un-realpath'd fixture root fails runtime_path_escape on every file-backend
// call.
$resolvedPrivateRoot = realpath($privateRoot);
if (is_string($resolvedPrivateRoot)) {
    $privateRoot = $resolvedPrivateRoot;
}
$path = $privateRoot . '/runtime/admission/counter.json';

try {
    $releaseA = _stattic_admission_counter_acquire($path, $limit, 60);
    if (!is_callable($releaseA)) {
        throw new RuntimeException('request A was not admitted');
    }

    $counterPath = admission_fixture_file_counter_path($path);
    $counter = json_decode((string) file_get_contents($counterPath), true, flags: JSON_THROW_ON_ERROR);
    // A surviving request may have refreshed updated_at after other holders
    // crashed. Generation age, not recent traffic, must trigger recovery.
    $counter['started_at'] = 1;
    $counter['updated_at'] = time();
    file_put_contents($counterPath, json_encode($counter, JSON_THROW_ON_ERROR) . "\n");

    $releaseB = _stattic_admission_counter_acquire($path, $limit, 60);
    if (!is_callable($releaseB)) {
        throw new RuntimeException('request B was not admitted after the stale reset');
    }
    $generationFileCountAfterRotation = admission_fixture_generation_file_count($path);

    $releaseA();
    $countAfterStaleRelease = admission_fixture_counter_value($path);

    $freshResults = [];
    $freshReleases = [];
    for ($attempt = 0; $attempt < $limit; $attempt++) {
        $release = _stattic_admission_counter_acquire($path, $limit, 60);
        $freshResults[] = is_callable($release);
        if (is_callable($release)) {
            $freshReleases[] = $release;
        }
    }

    $persistedCountAtLimit = admission_fixture_counter_value($path);

    foreach ($freshReleases as $release) {
        $release();
    }
    $releaseB();
    $countAfterCurrentReleases = admission_fixture_counter_value($path);

    $releaseAfterSlotsFreed = _stattic_admission_counter_acquire($path, $limit, 60);
    $admittedAfterSlotsFreed = is_callable($releaseAfterSlotsFreed);
    if ($admittedAfterSlotsFreed) {
        $releaseAfterSlotsFreed();
    }
    $finalPersistedCount = admission_fixture_counter_value($path);
    // A crash between the count-file write and the pointer publish (or
    // before the superseded file's unlink) orphans a generation file no
    // pointer references; the next rotation's reap must sweep it, so the
    // first post-rotation census below would read 2 if it survived.
    file_put_contents(
        $path . '.' . str_repeat('ab', 16),
        json_encode(['count' => 7, 'updated_at' => time()], JSON_THROW_ON_ERROR) . "\n",
    );
    $generationFileCountsAfterRotations = [];
    for ($rotation = 0; $rotation < 5; $rotation++) {
        $counterPath = admission_fixture_file_counter_path($path);
        $counter = json_decode((string) file_get_contents($counterPath), true, flags: JSON_THROW_ON_ERROR);
        $counter['started_at'] = 1;
        $counter['updated_at'] = time();
        file_put_contents($counterPath, json_encode($counter, JSON_THROW_ON_ERROR) . "\n");

        $release = _stattic_admission_counter_acquire($path, $limit, 60);
        if (!is_callable($release)) {
            throw new RuntimeException('request was not admitted after stale rotation');
        }
        $generationFileCountsAfterRotations[] = admission_fixture_generation_file_count($path);
        $release();
    }
    $mixedFileCutover = admission_fixture_mixed_file_cutover($privateRoot, $limit);

    echo json_encode([
        'request_b_admitted' => is_callable($releaseB),
        'generation_file_count_after_rotation' => $generationFileCountAfterRotation,
        'count_after_stale_release' => $countAfterStaleRelease,
        'fresh_results' => $freshResults,
        'persisted_count_at_limit' => $persistedCountAtLimit,
        'count_after_current_releases' => $countAfterCurrentReleases,
        'admitted_after_slots_freed' => $admittedAfterSlotsFreed,
        'final_persisted_count' => $finalPersistedCount,
        'generation_file_counts_after_rotations' => $generationFileCountsAfterRotations,
        'mixed_file_cutover' => $mixedFileCutover,
    ], JSON_THROW_ON_ERROR) . "\n";
} finally {
    exec('rm -rf ' . escapeshellarg($fixtureRoot));
}
