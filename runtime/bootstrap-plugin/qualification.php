<?php
declare(strict_types=1);
// Operator tooling, sent over authenticated SSH. Never load site/provider PHP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

function qualification_regular(string $path): string
{
    if (is_link($path) || !is_file($path) || filesize($path) > 1048576) throw new RuntimeException('qualification_metadata_invalid');
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException('qualification_metadata_unreadable');
    return $bytes;
}
function qualification_confined(string $path): void
{
    $current = getcwd();
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('qualification_path_invalid');
        $current .= '/' . $part;
        if (is_link($current)) throw new RuntimeException('qualification_path_symlink');
    }
}
function qualification_hash_tree(string $path, array &$files, int &$budget): void
{
    qualification_confined($path);
    if (is_file($path)) {
        $budget += filesize($path);
        if ($budget > 536870912 || count($files) >= 50000) throw new RuntimeException('qualification_evidence_budget');
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) throw new RuntimeException('qualification_evidence_unreadable');
        $files[$path] = $hash;
    } elseif (is_dir($path)) {
        $entries = scandir($path);
        if (!is_array($entries)) throw new RuntimeException('qualification_evidence_unreadable');
        foreach ($entries as $entry) if ($entry !== '.' && $entry !== '..') qualification_hash_tree($path . '/' . $entry, $files, $budget);
    } elseif (file_exists($path)) throw new RuntimeException('qualification_evidence_special_file');
}
function qualification_snapshot(array $input): array
{
    foreach (['.stattic', '.stattic/storage'] as $directory) {
        qualification_confined($directory);
        if (!is_dir($directory)) throw new RuntimeException('qualification_storage_missing');
    }
    qualification_confined('.stattic/storage/config.php');
    $metadata = stat('.stattic/storage/config.php');
    $storageMetadata = stat('.stattic/storage');
    if (!is_array($metadata) || !is_array($storageMetadata) || $metadata['uid'] !== $storageMetadata['uid'] || ($metadata['mode'] & 0022) !== 0) throw new RuntimeException('qualification_config_owner');
    $config = qualification_regular('.stattic/storage/config.php');
    $data = spacefast_config_data($config);
    if (($data['SPACEFAST_RUNTIME_INSTANCE_ID'] ?? '') !== $input['boxId']) throw new RuntimeException('qualification_config_identity');
    // Unknown source selections cannot be made eligible by this command.
    $selection = $data['SPACEFAST_RUNTIME_ENGINE_SOURCE'] ?? '';
    if ($selection !== '' && $selection !== 'site' && $selection !== ($input['selection'] ?? '')) throw new RuntimeException('qualification_config_selection');
    $proofPath = '.stattic/active-release-proof.json';
    qualification_confined($proofPath);
    $rawProof = file_exists($proofPath) ? qualification_regular($proofPath) : null;
    $proof = $rawProof === null ? [] : json_decode($rawProof, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($proof)) throw new RuntimeException('qualification_publication_invalid');
    if (file_exists('.stattic/install-transaction.json') || is_link('.stattic/install-transaction.json') || file_exists('.stattic/rollback-failure.json')) throw new RuntimeException('qualification_publication_unresolved');
    $files = [];
    $budget = 0;
    foreach (['.stattic/releases', '.stattic/release-authorities', '.stattic/incoming'] as $directory) qualification_hash_tree($directory, $files, $budget);
    foreach (['.stattic/runtime-install-*', '.stattic/release-stage-*'] as $pattern) {
        $paths = glob($pattern);
        if ($paths === false) throw new RuntimeException('qualification_evidence_unreadable');
        foreach ($paths as $path) qualification_hash_tree($path, $files, $budget);
    }
    // Pending jobs remain data, never execution candidates. Refuse the same
    // unattested work the installer refuses, without draining or editing it.
    $queue = '.stattic/storage/runtime/jobs/queue';
    qualification_confined($queue);
    if (is_dir($queue)) {
        foreach (glob($queue . '/*.json') ?: [] as $path) {
            $job = json_decode(qualification_regular($path), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($job)) throw new RuntimeException('qualification_job_unreadable');
            if (in_array($job['status'] ?? '', ['pending', 'running'], true) && (!is_string($job['attestation'] ?? null) || $job['attestation'] === '')) throw new RuntimeException('qualification_legacy_jobs_pending');
        }
    }
    ksort($files, SORT_STRING);
    $snapshot = [
        'boxId' => $input['boxId'],
        'cliReady' => spacefast_qualification_cli_ready(),
        'configSha256' => hash('sha256', $config),
        'configKeys' => array_keys($data),
        'configOwner' => $metadata['uid'],
        'configMode' => $metadata['mode'] & 07777,
        'nonce' => $proof['publication']['nonce'] ?? ($rawProof === null ? 'initial' : hash('sha256', $rawProof)),
        'publication' => $proof['publication'] ?? null,
        'revision' => $proof['revision'] ?? null,
        'evidence' => $files,
    ];
    // Establish existing physical ownership as data before accepting a plan.
    // Never make a corrupt/unknown release eligible by asking the installer to repair it.
    $pointer = trim(qualification_regular('.stattic/active-release'));
    if (!preg_match('#^releases/release-[a-zA-Z0-9._-]+$#D', $pointer) || !is_array($proof['publication'] ?? null) || !is_string($proof['revision'] ?? null)) throw new RuntimeException('qualification_original_unproven');
    $release = '.stattic/' . $pointer;
    qualification_confined($release);
    $manifest = json_decode(qualification_regular($release . '/engine-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !is_array($manifest['files'] ?? null) || !is_array($manifest['trees'] ?? null) || !is_array($manifest['aliases'] ?? null) || !is_array($manifest['executables'] ?? null)) throw new RuntimeException('qualification_original_manifest');
    $owned = [];
    foreach ($manifest['files'] as $path) {
        if (!is_string($path)) throw new RuntimeException('qualification_original_manifest');
        qualification_confined($release . '/' . $path);
        $owned[$path] = hash_file('sha256', $release . '/' . $path);
    }
    foreach ($manifest['trees'] as $tree) {
        $treeFiles = [];
        qualification_hash_tree($release . '/' . $tree['source'], $treeFiles, $budget);
        foreach ($treeFiles as $path => $hash) $owned[substr($path, strlen($release) + 1)] = $hash;
    }
    qualification_verify([
        'before' => array_merge($snapshot, ['nonce' => $proof['publication']['expected_nonce'] ?? '', 'evidence' => []]),
        'commandId' => $proof['publication']['command_id'] ?? '', 'revision' => $proof['revision'], 'nativeSha256' => $proof['native_sha256'] ?? '',
        'files' => $owned, 'manifest' => $manifest,
    ], $snapshot);
    return $snapshot;

}
function qualification_verify(array $input, array $snapshot): void
{
    if ($snapshot['configSha256'] !== $input['before']['configSha256'] || $snapshot['configOwner'] !== $input['before']['configOwner'] || $snapshot['configMode'] !== $input['before']['configMode']) throw new RuntimeException('qualification_config_changed');
    foreach ($input['before']['evidence'] as $path => $hash) {
        qualification_confined($path);
        if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) throw new RuntimeException('qualification_evidence_changed');
    }
    $proof = json_decode(qualification_regular('.stattic/active-release-proof.json'), true, 64, JSON_THROW_ON_ERROR);
    if (($input['files']['bin/stattic-runtime'] ?? null) !== $input['nativeSha256']) throw new RuntimeException('qualification_native_identity');
    if (($proof['publication']['command_id'] ?? '') !== $input['commandId'] || ($proof['revision'] ?? '') !== $input['revision'] || ($proof['native_sha256'] ?? '') !== $input['nativeSha256'] || ($proof['publication']['expected_nonce'] ?? '') !== $input['before']['nonce']) throw new RuntimeException('qualification_publication_changed');
    $pointer = trim(qualification_regular('.stattic/active-release'));
    if (!preg_match('#^releases/release-[a-zA-Z0-9._-]+$#D', $pointer)) throw new RuntimeException('qualification_pointer_invalid');
    $release = '.stattic/' . $pointer;
    qualification_confined($release);
    if (($proof['release'] ?? '') !== $pointer) throw new RuntimeException('qualification_proof_pointer_mismatch');
    $installed = [];
    $budget = 0;
    qualification_hash_tree($release, $installed, $budget);
    unset($installed[$release . '/.payload-identity']);
    $expected = $input['files'];
    ksort($expected, SORT_STRING);
    $relative = [];
    $payload = hash_init('sha256');
    foreach ($installed as $path => $hash) $relative[substr($path, strlen($release) + 1)] = $hash;
    ksort($relative, SORT_STRING);
    if ($relative !== $expected) throw new RuntimeException('qualification_installed_bytes_mismatch');
    foreach ($relative as $path => $hash) {
        $target = $release . '/' . $path;
        $mode = in_array($path, $input['manifest']['executables'], true) ? 0755 : 0644;
        if ((fileperms($target) & 07777) !== $mode) throw new RuntimeException('qualification_installed_mode');
        hash_update($payload, $path . "\0" . $mode . "\0" . filesize($target) . "\0");
        hash_update_file($payload, $target);
    }
    $payloadIdentity = hash_final($payload);
    $loaders = hash_init('sha256');
    $trees = $input['manifest']['trees'];
    $aliases = $input['manifest']['aliases'];
    usort($trees, static fn ($a, $b) => strcmp($a['path'], $b['path']));
    usort($aliases, static fn ($a, $b) => strcmp($a['path'], $b['path']));
    foreach ($trees as $tree) {
        foreach ($expected as $path => $hash) {
            if (!str_starts_with($path, $tree['source'] . '/')) continue;
            $target = $release . '/' . $path;
            $served = $tree['path'] . substr($path, strlen($tree['source']));
            hash_update($loaders, $served . "\0" . 0644 . "\0" . filesize($target) . "\0");
            hash_update_file($loaders, $target);
        }
    }
    foreach ($aliases as $alias) {
        $target = $alias['path'];
        qualification_confined($target);
        $mode = in_array($alias['source'], $input['manifest']['executables'], true) ? 0755 : 0644;
        if (!is_file($target) || (fileperms($target) & 07777) !== $mode || !hash_equals($expected[$alias['source']], hash_file('sha256', $target))) throw new RuntimeException('qualification_loader_bytes_mismatch');
        hash_update($loaders, $target . "\0" . $mode . "\0" . filesize($target) . "\0");
        hash_update_file($loaders, $target);
    }
    $loaderIdentity = hash_final($loaders);
    if (($proof['payload_identity'] ?? '') !== $payloadIdentity || ($proof['loader_identity'] ?? '') !== $loaderIdentity || trim(qualification_regular('.stattic/loader-version')) !== $loaderIdentity) throw new RuntimeException('qualification_payload_identity');
    foreach ($trees as $tree) {
        qualification_confined(dirname($tree['path']));
        $store = dirname($tree['path']) . '/spacefast-tree-releases/' . basename($tree['path']);
        qualification_confined($store);
        $link = is_link($tree['path']) ? readlink($tree['path']) : false;
        $prefix = 'spacefast-tree-releases/' . basename($tree['path']) . '/' . $loaderIdentity . '-';
        if (!is_string($link) || !str_starts_with($link, $prefix) || str_contains(substr($link, strlen($prefix)), '/')) throw new RuntimeException('qualification_tree_owner');
        $target = dirname($tree['path']) . '/' . $link;
        $publicFiles = [];
        qualification_hash_tree($target, $publicFiles, $budget);
        $treeFiles = [];
        foreach ($expected as $path => $hash) {
            if (!str_starts_with($path, $tree['source'] . '/')) continue;
            $file = $target . substr($path, strlen($tree['source']));
            if ((fileperms($file) & 07777) !== 0644) throw new RuntimeException('qualification_tree_mode');
            $treeFiles[$file] = $hash;
        }
        ksort($publicFiles, SORT_STRING);
        ksort($treeFiles, SORT_STRING);
        if ($publicFiles !== $treeFiles) throw new RuntimeException('qualification_tree_mismatch');
    }
    $authority = json_decode(qualification_regular('.stattic/release-authorities/' . basename($pointer) . '.json'), true, 64, JSON_THROW_ON_ERROR);
    if (($authority['format'] ?? '') !== 'spacefast.runtime.release-authority.v1' || ($authority['release'] ?? '') !== basename($pointer) || ($proof['format'] ?? '') !== 'spacefast.runtime.active-release-proof.v1') throw new RuntimeException('qualification_authority_invalid');
    foreach (['revision', 'payload_identity', 'loader_identity'] as $field) {
        if (!is_string($proof[$field] ?? null) || ($authority[$field] ?? null) !== $proof[$field]) throw new RuntimeException('qualification_authority_mismatch');
    }
}
function qualification_main(): void
{
    if (!spacefast_qualification_cli_ready()) throw new RuntimeException('qualification_php_unavailable');
    $line = fgets(STDIN, 16777216);
    if (!is_string($line)) throw new RuntimeException('qualification_input_required');
    $input = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('qualification_input_invalid');
    if ($input['mode'] === 'inspect') {
        echo json_encode(qualification_snapshot($input), JSON_THROW_ON_ERROR) . "\n";
        return;
    }
    if ($input['mode'] === 'stage') {
        $snapshot = qualification_snapshot($input);
        if ($snapshot != $input['before']) throw new RuntimeException('qualification_snapshot_changed');
        if (!hash_equals($input['installerSha256'], hash('sha256', $input['installer']))) throw new RuntimeException('qualification_installer_identity');
        $directory = '.stattic/installers/' . hash('sha256', hash('sha256', $input['installer']) . hash('sha256', $input['configParser']));
        foreach (['.stattic/installers', $directory] as $path) {
            qualification_confined($path);
            if (!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('qualification_stage_failed');
        }
        foreach (['installer.php' => $input['installer'], 'config-data.php' => $input['configParser']] as $name => $bytes) {
            $target = $directory . '/' . $name;
            qualification_confined($target);
            if (!file_exists($target)) {
                $stream = fopen($target, 'x');
                if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream) || !chmod($target, 0444)) throw new RuntimeException('qualification_stage_failed');
                fclose($stream);
            }
            if (!is_file($target) || !hash_equals(hash('sha256', $bytes), hash_file('sha256', $target))) throw new RuntimeException('qualification_stage_conflict');
        }
        echo json_encode(['installerPath' => $directory . '/installer.php'], JSON_THROW_ON_ERROR) . "\n";
        return;
    }
    if ($input['mode'] !== 'fence') throw new RuntimeException('qualification_mode_invalid');
    // The proof holds both existing locks until the short database stamp commits.
    // Open existing files only: a proof cannot make an absent lock look installed.
    $locks = [];
    foreach (['.stattic/installer.lock', '.stattic/publication.lock'] as $path) {
        qualification_confined($path);
        if (!is_file($path)) throw new RuntimeException('qualification_lock_missing');
        $lock = fopen($path, 'r');
        if ($lock === false || !flock($lock, LOCK_SH | LOCK_NB)) throw new RuntimeException('qualification_publication_busy');
        $locks[] = $lock;
    }
    $snapshot = qualification_snapshot($input);
    qualification_verify($input, $snapshot);
    echo json_encode($snapshot, JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
    stream_set_timeout(STDIN, 60);
    while (true) {
        $command = fgets(STDIN, 128);
        if ($command === "commit\n") break;
        if (!is_string($command) || !preg_match('/^check:[a-f0-9-]{36}\n$/D', $command)) throw new RuntimeException('qualification_commit_not_acknowledged');
        echo $command;
        fflush(STDOUT);
    }
    foreach ($locks as $lock) { flock($lock, LOCK_UN); fclose($lock); }
}
try { qualification_main(); }
catch (Throwable $error) { $code = $error->getMessage(); fwrite(STDERR, preg_match('/^qualification_[a-z_]+$/D', $code) ? $code . "\n" : "qualification_refused\n"); exit(1); }
