<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Repli SPA : renvoie public/spa.html (build de web/) pour les routes GET « propres » du SPA
 * (voir routes/web.php et pathPattern()). Hors production, un texte indique si le build manque.
 */
class SpaController extends Controller
{
    /**
     * Préfixes réservés (API et dossiers de l'application) : jamais servis par le SPA.
     */
    public const RESERVED_PREFIXES = [
        'api', 'sanctum', 'app', 'bootstrap', 'config', 'database', 'lang',
        'node_modules', 'resources', 'routes', 'storage', 'tests', 'vendor',
    ];

    /**
     * Motif du chemin accepté par la route de repli (sans « / » initial) : aucun préfixe réservé
     * (insensible à la casse) et aucun point (ni segment caché, ni extension de fichier).
     */
    public static function pathPattern(): string
    {
        return '(?!(?i:'.implode('|', self::RESERVED_PREFIXES).')(?:/|$))[^.]*';
    }

    public function __invoke(): Response
    {
        $path = public_path('spa.html');

        if (is_file($path)) {
            return new BinaryFileResponse($path, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-cache',
            ], true, null, false, false);
        }

        if (app()->isProduction()) {
            throw new NotFoundHttpException;
        }

        return new Response(
            "SPA non construite — lancez `npm run build` dans web/.\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-cache'],
        );
    }
}
