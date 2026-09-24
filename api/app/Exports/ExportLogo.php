<?php

namespace App\Exports;

/**
 * Logo de l'église embarqué dans les exports (PDF).
 *
 * Le fichier vit dans `api/` (resources/images/logo.png) et JAMAIS dans `web/` : le script de
 * release ne copie que le build du SPA, un export ne doit dépendre d'aucun fichier du frontend.
 * Il a été généré une fois depuis `web/public/icon-512.png` avec PHP GD en ligne de commande
 * (imagecreatefrompng, détourage du fond violet plat, imagecrop, imagescale, imagepng) :
 * 300 × 307 px, fond transparent, ~57 Ko.
 *
 * Le PDF l'intègre en data URI base64 calculé ici : dompdf tourne avec isRemoteEnabled = false
 * (comme isPhpEnabled et isJavascriptEnabled) et ne doit charger aucune ressource par URL.
 */
class ExportLogo
{
    /** Chemin du logo, relatif à resources/. */
    public const FILE = 'images/logo.png';

    public const MIME = 'image/png';

    public function path(): string
    {
        return resource_path(self::FILE);
    }

    /**
     * `data:image/png;base64,…`, ou chaîne vide si le fichier est absent ou illisible :
     * un logo manquant ne doit jamais faire échouer un export (l'en-tête s'affiche sans image).
     */
    public function dataUri(): string
    {
        $path = $this->path();
        $binary = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        return $binary === false || $binary === ''
            ? ''
            : 'data:'.self::MIME.';base64,'.base64_encode($binary);
    }
}
