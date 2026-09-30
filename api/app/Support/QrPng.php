<?php

namespace App\Support;

use RuntimeException;

/**
 * Rendu d'un QrCode en PNG noir et blanc, pour intégration en data URI dans un PDF dompdf
 * (aucune ressource distante : `isRemoteEnabled` reste à false).
 *
 * L'image est une palette de deux couleurs : un QR de version 4 rendu à 8 px par module pèse
 * moins de 1 Ko, et reste net à l'impression (25 mm pour 41 modules = 0,6 mm par module,
 * largement au-dessus du seuil de lecture des téléphones).
 */
final class QrPng
{
    /** Côté d'un module, en pixels. */
    public const MODULE_PIXELS = 8;

    /** Marge claire autour du code, en modules (4 = minimum imposé par la norme). */
    public const QUIET_ZONE = 4;

    public static function dataUri(
        QrCode $code,
        int $modulePixels = self::MODULE_PIXELS,
        int $quietZone = self::QUIET_ZONE,
    ): string {
        return 'data:image/png;base64,'.base64_encode(self::png($code, $modulePixels, $quietZone));
    }

    public static function png(
        QrCode $code,
        int $modulePixels = self::MODULE_PIXELS,
        int $quietZone = self::QUIET_ZONE,
    ): string {
        $modules = $code->size();
        $side = ($modules + 2 * $quietZone) * $modulePixels;
        $image = imagecreate($side, $side);

        if ($image === false) {
            throw new RuntimeException('Impossible de créer l\'image du QR code.');
        }

        // La première couleur allouée remplit l'image : le fond clair, puis les modules sombres.
        imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        for ($row = 0; $row < $modules; $row++) {
            for ($column = 0; $column < $modules; $column++) {
                if (! $code->isDark($row, $column)) {
                    continue;
                }

                $x = ($column + $quietZone) * $modulePixels;
                $y = ($row + $quietZone) * $modulePixels;
                imagefilledrectangle($image, $x, $y, $x + $modulePixels - 1, $y + $modulePixels - 1, (int) $black);
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
