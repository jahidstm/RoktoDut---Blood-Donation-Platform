<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/** QR code as a PNG drawn with GD. The bundled PNG renderer needs the imagick extension. */
class QrPng
{
    public static function save(string $text, string $path, int $size, int $margin = 2): void
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth() + 2 * $margin;
        $scale = max(1, intdiv($size, $modules));
        $side = $modules * $scale;

        $image = imagecreatetruecolor($side, $side);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $margin) * $scale;
                    $top = ($y + $margin) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
                }
            }
        }

        // Exact requested size, so callers can position it without knowing the module count.
        $scaled = imagescale($image, $size, $size, IMG_NEAREST_NEIGHBOUR);
        imagepng($scaled, $path);
        imagedestroy($image);
        imagedestroy($scaled);
    }
}
