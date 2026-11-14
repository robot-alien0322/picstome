<?php

namespace App\Support;

use Imagick;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/**
 * Eugene fork: "fit inside a square box" resize for web/download sizes.
 * Big JPEGs are decoded at a reduced scale (libjpeg DCT scaling via the jpeg:size hint), but
 * never below 2x the target box, then downsized with Lanczos and a light unsharp mask.
 * (Decoding straight at box size + a triangle filter was faster but visibly soft.)
 */
class FastResize
{
    public static function toFile(string $source, string $dest, int $box, string $format, int $quality = 90): void
    {
        if (! class_exists(Imagick::class)) {
            Image::load($source)->fit(Fit::Max, $box, $box)->quality($quality)->save($dest);

            return;
        }

        $im = new Imagick;
        $im->setOption('jpeg:size', (2 * $box).'x'.(2 * $box));
        $im->readImage($source);
        self::autoOrient($im);

        if (max($im->getImageWidth(), $im->getImageHeight()) > $box) {
            $im->resizeImage($box, $box, Imagick::FILTER_LANCZOS, 1, true);
            $im->unsharpMaskImage(0, 0.6, 0.5, 0.02);
        }

        // Keep the colour profile, drop the rest of the metadata
        $profiles = $im->getImageProfiles('icc', true);
        $im->stripImage();
        if (! empty($profiles['icc'])) {
            $im->profileImage('icc', $profiles['icc']);
        }

        $im->setImageFormat($format);
        $im->setImageCompressionQuality($quality);

        if ($format === 'jpeg') {
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }

        $im->writeImage($format.':'.$dest);
        $im->clear();
    }

    /**
     * Several sizes from ONE decode of the source (largest first, each made from the previous one).
     * Each output: ['box' => int, 'format' => 'webp'|'jpeg', 'dest' => path, 'quality' => int].
     */
    public static function multi(string $source, array $outputs): void
    {
        usort($outputs, fn ($a, $b) => $b['box'] <=> $a['box']);
        $max = $outputs[0]['box'];

        if (! class_exists(Imagick::class) || $max > 2048) {
            foreach ($outputs as $o) {
                self::toFile($source, $o['dest'], $o['box'], $o['format'], $o['quality'] ?? 90);
            }

            return;
        }

        $im = new Imagick;
        $im->setOption('jpeg:size', (2 * $max).'x'.(2 * $max));
        $im->readImage($source);
        self::autoOrient($im);

        $profiles = $im->getImageProfiles('icc', true);
        $im->stripImage();
        if (! empty($profiles['icc'])) {
            $im->profileImage('icc', $profiles['icc']);
        }

        foreach ($outputs as $n => $o) {
            if (max($im->getImageWidth(), $im->getImageHeight()) > $o['box']) {
                $im->resizeImage($o['box'], $o['box'], Imagick::FILTER_LANCZOS, 1, true);

                // Full sharpening on the first size; smaller sizes are made from that already-sharpened
                // image, so they only get a light touch (matches the single-size result).
                $im->unsharpMaskImage(0, 0.6, $n === 0 ? 0.5 : 0.25, 0.02);
            }

            $out = clone $im;
            $out->setImageFormat($o['format']);
            $out->setImageCompressionQuality($o['quality'] ?? 90);

            if ($o['format'] === 'jpeg') {
                $out->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            }

            $out->writeImage($o['format'].':'.$o['dest']);
            $out->clear();
        }

        $im->clear();
    }

    private static function autoOrient(Imagick $im): void
    {
        switch ($im->getImageOrientation()) {
            case Imagick::ORIENTATION_TOPRIGHT: $im->flopImage(); break;
            case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180); break;
            case Imagick::ORIENTATION_BOTTOMLEFT: $im->flipImage(); break;
            case Imagick::ORIENTATION_LEFTTOP: $im->transposeImage(); break;
            case Imagick::ORIENTATION_RIGHTTOP: $im->rotateImage('#000', 90); break;
            case Imagick::ORIENTATION_RIGHTBOTTOM: $im->transverseImage(); break;
            case Imagick::ORIENTATION_LEFTBOTTOM: $im->rotateImage('#000', -90); break;
        }

        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
