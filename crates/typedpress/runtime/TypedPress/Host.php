<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * The server's operating system under Node's names, for `process.platform` and
 * `navigator.platform` in compiled code. The values are read when PHP runs, never decided on the
 * build machine. See docs/decisions/2026-11-14-host-platform-detection.md.
 */
final class Host
{
    /** @var array{0: string, 1: string}|null [process.platform, navigator.platform] for this request. */
    private static ?array $names = null;

    /** `process.platform`: `linux`, `darwin`, `win32`, `freebsd`, ... */
    public static function platform(): string
    {
        return self::current()[0];
    }

    /** `navigator.platform`: `Linux x86_64`, `MacIntel`, `Win32`, ... */
    public static function navigatorPlatform(): string
    {
        return self::current()[1];
    }

    /**
     * Node's `[process.platform, navigator.platform]` for a PHP_OS_FAMILY, a PHP_OS and a
     * `php_uname('m')` machine name. `$machine` is null when the host disables php_uname: 64-bit
     * PHP then reports `x64` and 32-bit PHP `ia32`, an approximation that only changes the suffix
     * on Linux, BSD and Solaris. Pure, so every operating system is testable on one runner.
     *
     * @return array{0: string, 1: string}
     */
    public static function names(string $family, string $os, ?string $machine, int $intSize = PHP_INT_SIZE): array
    {
        $platform = match ($family) {
            'Windows' => 'win32',
            'Darwin' => 'darwin',
            'Solaris' => 'sunos',
            default => strtolower($os),
        };
        $arch = $machine === null ? ($intSize === 8 ? 'x64' : 'ia32') : self::arch($machine);

        return [$platform, self::navigator($platform, $arch)];
    }

    /** @return array{0: string, 1: string} */
    private static function current(): array
    {
        if (self::$names === null) {
            $machine = function_exists('php_uname') ? php_uname('m') : '';
            self::$names = self::names(PHP_OS_FAMILY, PHP_OS, $machine === '' ? null : $machine);
        }

        return self::$names;
    }

    /** Node's `process.arch` for a `php_uname('m')` machine name. */
    private static function arch(string $machine): string
    {
        $m = strtolower($machine);

        return match (true) {
            $m === 'x86_64' || $m === 'amd64' => 'x64',
            $m === 'aarch64' || $m === 'arm64' => 'arm64',
            $m === 'x86' || preg_match('/^i[3-6]86$/', $m) === 1 => 'ia32',
            $m === 'armv6l' || $m === 'armv7l' => 'arm',
            $m === 'ppc64' || $m === 'ppc64le' => 'ppc64',
            $m === 'loongarch64' => 'loong64',
            default => $m,
        };
    }

    /** Node's getNavigatorPlatform(arch, platform) (lib/internal/navigator.js). */
    private static function navigator(string $platform, string $arch): string
    {
        return match ($platform) {
            'darwin' => 'MacIntel',
            'win32' => 'Win32',
            'aix' => 'AIX',
            'linux' => 'Linux ' . match ($arch) { 'ia32' => 'i686', 'x64' => 'x86_64', default => $arch },
            'freebsd', 'openbsd' => ($platform === 'freebsd' ? 'FreeBSD ' : 'OpenBSD ')
                . match ($arch) { 'ia32' => 'i386', 'x64' => 'amd64', default => $arch },
            'sunos' => 'SunOS ' . ($arch === 'ia32' ? 'i86pc' : $arch),
            default => ucfirst($platform) . ' ' . $arch,
        };
    }
}
