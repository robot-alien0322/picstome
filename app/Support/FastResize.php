<?php

namespace App\Support;

use Imagick;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

/**
 * Eugene fork: fast "fit inside a square box" resize. Big JPEGs are decoded straight at a
 * reduced scale (libjpeg DCT scaling via the jpeg:size hint) instead of decoding all
 * 33 MP first, which is several times faster for web/download sizes.
 */
class FastResize
{
    public static function toFile(string $source, string $dest, int $box, string $format, int $quality = 90): void
    {
        // Measured on 33 MP JPEGs: the scaled decode wins up to ~2048 px; above that GD is as fast.
        if (! class_exists(Imagick::class) || $box > 2048) {
            Image::load($source)->fit(Fit::Max, $box, $box)->quality($quality)->save($dest);

            return;
        }

        $im = new Imagick;
        $im->setOption('jpeg:size', $box.'x'.$box);
        $im->readImage($source);
        self::autoOrient($im);

        if (max($im->getImageWidth(), $im->getImageHeight()) > $box) {
            $im->resizeImage($box, $box, Imagick::FILTER_TRIANGLE, 1, true);
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
