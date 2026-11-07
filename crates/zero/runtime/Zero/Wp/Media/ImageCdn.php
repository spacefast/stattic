<?php

declare(strict_types=1);

namespace Zero\Wp\Media;

use Zero\Media\Images;

/**
 * Optional Jetpack Image CDN adapter (phase 2 §7.2). Inert unless Jetpack (or Jetpack Boost) has the
 * Image CDN enabled on a site that is not in offline mode: then size URLs point at the CDN and core
 * makes no local `zero_*` files. Private files never go through here.
 */
final class ImageCdn
{
    private const CORE = '\Automattic\Jetpack\Image_CDN\Image_CDN_Core';
    private const MODULE = '\Automattic\Jetpack\Image_CDN\Image_CDN';
    private const STATUS = '\Automattic\Jetpack\Status';

    public static function active(): bool
    {
        return class_exists(self::CORE) && class_exists(self::MODULE) && (self::MODULE)::is_enabled()
            && !(class_exists(self::STATUS) && (new (self::STATUS)())->is_offline_mode());
    }

    /**
     * Photon arguments for one size: `resize` + a pixel `crop` (the focal rectangle) for cover, `fit`
     * for inside; [] when the original already fits.
     *
     * @return array{array<string, string>, int, int} [args, width, height]
     */
    public static function args(int $origW, int $origH, array $size, ?array $focal): array
    {
        $cover = ($size['fit'] ?? 'inside') === 'cover';
        $dims = Images::dimensions($origW, $origH, (int) $size['width'], isset($size['height']) ? (int) $size['height'] : null, $cover, $focal);
        if ($dims === null) {
            return [[], $origW, $origH];
        }
        [, , $x, $y, $w, $h, $sw, $sh] = $dims;

        return [$cover ? ['crop' => "{$x}px,{$y}px,{$sw}px,{$sh}px", 'resize' => "$w,$h"] : ['fit' => "$w,$h"], $w, $h];
    }

    /** @return array{url: string, width: int, height: int} */
    public static function size(string $url, int $origW, int $origH, array $size, ?array $focal): array
    {
        [$args, $w, $h] = self::args($origW, $origH, $size, $focal);
        // Other `jetpack_photon_pre_args` filters may rewrite crops for their own URLs; ours keeps the focal rectangle.
        $keep = static fn ($given, $image) => $image === $url && is_array($given) ? $args + $given : $given;
        add_filter('jetpack_photon_pre_args', $keep, PHP_INT_MAX, 2);
        try {
            $cdn = (string) (self::CORE)::cdn_url($url, $args);
        } finally {
            remove_filter('jetpack_photon_pre_args', $keep, PHP_INT_MAX);
        }

        return ['url' => $cdn, 'width' => $w, 'height' => $h];
    }
}
