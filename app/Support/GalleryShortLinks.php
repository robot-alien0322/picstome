<?php

namespace App\Support;

use App\Models\Gallery;
use Illuminate\Support\Str;

/**
 * Short links (Eugene fork): /g/{code} -> /shares/{ulid}/{slug}.
 */
class GalleryShortLinks
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/';

    /** Random code without look-alike characters (0/o, 1/l/i). */
    public static function randomCode(int $length = 6): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Gallery::where('short_code', $code)->exists());

        return $code;
    }

    public static function ensure(Gallery $gallery): string
    {
        if (empty($gallery->short_code)) {
            $gallery->forceFill(['short_code' => static::randomCode()])->saveQuietly();
        }

        return $gallery->short_code;
    }

    public static function url(Gallery $gallery): string
    {
        return url('/g/'.static::ensure($gallery));
    }

    public static function normalize(string $code): string
    {
        return Str::of($code)->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->toString();
    }
}
