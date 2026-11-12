<?php

namespace App\Support;

use Throwable;

/**
 * Eugene fork: display width/height of an image, swapped for EXIF-rotated JPEGs.
 * Stored on photos so justified galleries can lay out before the images load.
 */
class PhotoDimensions
{
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    /** @return array{0:int,1:int}|null */
    public static function fromFile(?string $path): ?array
    {
        if (! $path || ! is_file($path)) {
            return null;
        }

        return self::oriented(@getimagesize($path), fn () => @exif_read_data($path));
    }

    /** Works on just the start of a file: the size and EXIF live in the header. */
    public static function fromBytes(string $bytes): ?array
    {
        return self::oriented(
            @getimagesizefromstring($bytes),
            fn () => @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes))
        );
    }

    private static function oriented(mixed $info, callable $exif): ?array
    {
        if (! is_array($info) || empty($info[0]) || empty($info[1]) || ! in_array($info[2] ?? null, self::TYPES, true)) {
            return null;
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            try {
                $data = $exif();
            } catch (Throwable) {
                $data = null;
            }

            if (is_array($data) && in_array((int) ($data['Orientation'] ?? 1), [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }

        return [$width, $height];
    }
}
