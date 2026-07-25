<?php
declare(strict_types=1);

require_once __DIR__ . '/../bin/admit.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_remove(string $path): void
{
    stattic_remove_tree($path);
}

/** @return array<string,mixed> */
function test_bundle(string $root, string $spaceId = 'space', string $versionId = 'version'): array
{
    $payload = $root . '/payload';
    mkdir($payload . '/files', 0700, true);
    file_put_contents($payload . '/files/index.html', '<h1>Hello</h1>');
    file_put_contents($payload . '/serving.php', "<?php return ['format' => 'fixture'];\n");
    $artifacts = [];
    foreach (['files/index.html', 'serving.php'] as $path) {
        $bytes = (string) file_get_contents($payload . '/' . $path);
        $artifacts[] = [
            'path' => $path,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ];
    }
    $contentDigest = stattic_content_digest($artifacts);
    $descriptor = [
        'format' => STATTIC_BUNDLE_FORMAT,
        'profile' => STATTIC_PORTABLE_STATIC_PROFILE,
        'spaceId' => $spaceId,
        'versionId' => $versionId,
        'contentDigest' => $contentDigest,
        'bindingDigest' => null,
        'deploymentDigest' => stattic_deployment_digest(
            STATTIC_PORTABLE_STATIC_PROFILE,
            $contentDigest,
            null
        ),
        'builder' => ['abi' => 'stattic.builder.v1', 'version' => 'test'],
        'requirements' => [
            'runtimeAbi' => 'static-runtime-v2',
            'serverBuild' => false,
            'rustFinalizer' => false,
            'zeroRunner' => false,
        ],
        'artifacts' => $artifacts,
    ];
    stattic_write_json($root . '/bundle.json', $descriptor);
    return $descriptor;
}

$root = sys_get_temp_dir() . '/stattic-bundle-admission-' . bin2hex(random_bytes(8));
$bundle = $root . '/bundle';
$storage = $root . '/storage';
mkdir($bundle, 0700, true);

try {
    $descriptor = test_bundle($bundle);
    $admitted = stattic_admit_bundle($bundle, $storage);
    test_assert($admitted['status'] === 'admitted', 'first admission did not commit');
    test_assert($admitted['rustFinalizerRequired'] === false, 'admission requested Rust');
    test_assert(
        file_get_contents($storage . '/spaces/space/versions/version/files/index.html') === '<h1>Hello</h1>',
        'admitted payload differs'
    );
    $replayed = stattic_admit_bundle($bundle, $storage);
    test_assert($replayed['status'] === 'already_admitted', 'exact replay was not idempotent');

    file_put_contents($bundle . '/payload/files/index.html', 'tampered');
    try {
        stattic_admit_bundle($bundle, $storage);
        throw new RuntimeException('tampered payload was accepted');
    } catch (RuntimeException $error) {
        test_assert(
            $error->getMessage() === 'bundle_artifact_manifest_mismatch',
            'tampered payload failed for the wrong reason'
        );
    }
    file_put_contents($bundle . '/payload/files/index.html', '<h1>Hello</h1>');

    $descriptor['requirements']['rustFinalizer'] = true;
    stattic_write_json($bundle . '/bundle.json', $descriptor);
    try {
        stattic_admit_bundle($bundle, $storage);
        throw new RuntimeException('Rust-requiring portable bundle was accepted');
    } catch (RuntimeException $error) {
        test_assert(
            $error->getMessage() === 'bundle_requirements_invalid',
            'invalid requirements failed for the wrong reason'
        );
    }
    fwrite(STDOUT, "bundle admission: ok\n");
} finally {
    test_remove($root);
}
