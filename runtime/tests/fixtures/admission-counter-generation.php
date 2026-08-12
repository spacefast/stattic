<?php
declare(strict_types=1);

// The production jail has APCu, while the portable runtime test toolchain may
// not. Keep the fixture runnable in both places with the small integer-cache
// surface this counter uses. The same scenario is exercised against the real
// extension whenever it is loaded.
if (!function_exists('apcu_add')) {
    $apcuFixtureValues = [];

    function apcu_add(string $key, mixed $value, int $ttl = 0): bool
    {
        global $apcuFixtureValues;
        if (array_key_exists($key, $apcuFixtureValues)) {
            return false;
        }
        $apcuFixtureValues[$key] = $value;
        return true;
    }

    function apcu_fetch(string $key, ?bool &$success = null): mixed
    {
        global $apcuFixtureValues;
        $success = array_key_exists($key, $apcuFixtureValues);
        return $success ? $apcuFixtureValues[$key] : false;
    }

    function apcu_inc(string $key, int $step = 1, ?bool &$success = null, int $ttl = 0): int|false
    {
        global $apcuFixtureValues;
        if (!isset($apcuFixtureValues[$key]) || !is_int($apcuFixtureValues[$key])) {
            $success = false;
            return false;
        }
        $success = true;
        $apcuFixtureValues[$key] += $step;
        return $apcuFixtureValues[$key];
    }

    function apcu_dec(string $key, int $step = 1, ?bool &$success = null): int|false
    {
        return apcu_inc($key, -$step, $success);
    }

    function apcu_exists(string $key): bool
    {
        global $apcuFixtureValues;
        return array_key_exists($key, $apcuFixtureValues);
    }

    function apcu_cas(string $key, int $old, int $new): bool
    {
        global $apcuFixtureValues;
        if (($apcuFixtureValues[$key] ?? null) !== $old) {
            return false;
        }
        $apcuFixtureValues[$key] = $new;
        return true;
    }

    function apcu_delete(string $key): bool
    {
        global $apcuFixtureValues;
        $found = array_key_exists($key, $apcuFixtureValues);
        unset($apcuFixtureValues[$key]);
        return $found;
    }

    function apcu_clear_cache(): bool
    {
        global $apcuFixtureValues;
        $apcuFixtureValues = [];
        return true;
    }
}

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

function admission_fixture_counter_value(string $backend, string $path, string $key): ?int
{
    if ($backend === 'file') {
        $counter = json_decode((string) file_get_contents(admission_fixture_file_counter_path($path)), true, flags: JSON_THROW_ON_ERROR);
        return is_int($counter['count'] ?? null) ? $counter['count'] : null;
    }

    $generation = apcu_fetch($key . ':generation', $generationFound);
    if (!$generationFound || !is_string($generation)) {
        return null;
    }
    $count = apcu_fetch($key . ':' . $generation, $countFound);
    return $countFound && is_int($count) ? $count : null;
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
    $key = 'spacefast:test:admission:legacy:' . bin2hex(random_bytes(8));

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
    $releaseB = _stattic_admission_counter_acquire($privateRoot, $key, $path, $limit, 60);
    if (!is_callable($releaseB)) {
        throw new RuntimeException('current request B was shed by abandoned legacy slots at cutover');
    }
    $legacyPathExistsAfterCutover = is_file($path);
    $countAfterCutover = admission_fixture_counter_value('file', $path, $key);

    // Old-code holders finish: their releases recreate/decrement only the
    // legacy path and must not touch the new generation's count.
    foreach ($legacyReleases as $release) {
        $release();
    }
    $countAfterLegacyRelease = admission_fixture_counter_value('file', $path, $key);

    $freshResults = [];
    $freshReleases = [];
    for ($attempt = 0; $attempt < $limit; $attempt++) {
        $release = _stattic_admission_counter_acquire($privateRoot, $key, $path, $limit, 60);
        $freshResults[] = is_callable($release);
        if (is_callable($release)) {
            $freshReleases[] = $release;
        }
    }
    $persistedCountAtLimit = admission_fixture_counter_value('file', $path, $key);

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
        'final_persisted_count' => admission_fixture_counter_value('file', $path, $key),
    ];
}

$backend = getenv('SPACEFAST_ADMISSION_COUNTER_BACKEND');
if ($backend !== 'file' && $backend !== 'apcu') {
    fwrite(STDERR, "SPACEFAST_ADMISSION_COUNTER_BACKEND must be file or apcu\n");
    exit(2);
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
$key = 'spacefast:test:admission:' . bin2hex(random_bytes(8));

try {
    if ($backend === 'apcu') {
        apcu_clear_cache();
    }

    $releaseA = _stattic_admission_counter_acquire($privateRoot, $key, $path, $limit, 60);
    if (!is_callable($releaseA)) {
        throw new RuntimeException('request A was not admitted');
    }

    if ($backend === 'file') {
        $counterPath = admission_fixture_file_counter_path($path);
        $counter = json_decode((string) file_get_contents($counterPath), true, flags: JSON_THROW_ON_ERROR);
        $counter['updated_at'] = 1;
        file_put_contents($counterPath, json_encode($counter, JSON_THROW_ON_ERROR) . "\n");
    } else {
        // Expire both representations so this scenario also reproduces the
        // old single-key implementation's release-steals-recreated-slot bug.
        apcu_delete($key);
        apcu_delete($key . ':generation');
    }

    $releaseB = _stattic_admission_counter_acquire($privateRoot, $key, $path, $limit, 60);
    if (!is_callable($releaseB)) {
        throw new RuntimeException('request B was not admitted after the stale reset');
    }
    $generationFileCountAfterRotation = $backend === 'file'
        ? admission_fixture_generation_file_count($path)
        : null;

    $releaseA();
    $countAfterStaleRelease = admission_fixture_counter_value($backend, $path, $key);

    $freshResults = [];
    $freshReleases = [];
    for ($attempt = 0; $attempt < $limit; $attempt++) {
        $release = _stattic_admission_counter_acquire($privateRoot, $key, $path, $limit, 60);
        $freshResults[] = is_callable($release);
        if (is_callable($release)) {
            $freshReleases[] = $release;
        }
    }

    $persistedCountAtLimit = admission_fixture_counter_value($backend, $path, $key);

    foreach ($freshReleases as $release) {
        $release();
    }
    $releaseB();
    $countAfterCurrentReleases = admission_fixture_counter_value($backend, $path, $key);

    $releaseAfterSlotsFreed = _stattic_admission_counter_acquire($privateRoot, $key, $path, $limit, 60);
    $admittedAfterSlotsFreed = is_callable($releaseAfterSlotsFreed);
    if ($admittedAfterSlotsFreed) {
        $releaseAfterSlotsFreed();
    }
    $finalPersistedCount = admission_fixture_counter_value($backend, $path, $key);
    $generationFileCountsAfterRotations = null;
    if ($backend === 'file') {
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
            $counter['updated_at'] = 1;
            file_put_contents($counterPath, json_encode($counter, JSON_THROW_ON_ERROR) . "\n");

            $release = _stattic_admission_counter_acquire($privateRoot, $key, $path, $limit, 60);
            if (!is_callable($release)) {
                throw new RuntimeException('request was not admitted after stale rotation');
            }
            $generationFileCountsAfterRotations[] = admission_fixture_generation_file_count($path);
            $release();
        }
    }
    $mixedFileCutover = $backend === 'file'
        ? admission_fixture_mixed_file_cutover($privateRoot, $limit)
        : null;

    echo json_encode([
        'backend' => $backend,
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
    if ($backend === 'apcu') {
        apcu_clear_cache();
    }
    exec('rm -rf ' . escapeshellarg($fixtureRoot));
}
