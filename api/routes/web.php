<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Repli SPA
|--------------------------------------------------------------------------
|
| Seules les routes « propres » du SPA (/, /visite/1, /admin/visiteurs/12, /qrcode…) reçoivent
| public/spa.html. Chargé hors du groupe « web » (bootstrap/app.php) : aucune session ni cookie.
|
| Ne correspondent JAMAIS (=> 404, JSON pour /api et /sanctum) :
| - /api… et /sanctum… : une route API inconnue répond 404 JSON « not_found » ;
| - tout chemin contenant un point : segment caché (/.env, /.git/config) ou fichier
|   (/composer.json, /vendor/autoload.php, /x.php) ;
| - les dossiers de l'application (/vendor/, /storage/, /config/…), pour que le test de fumée
|   du déploiement ne reçoive jamais 200 sur ces chemins.
|
*/

Route::fallback(SpaController::class)
    ->where('fallbackPlaceholder', SpaController::pathPattern())
    ->name('spa');
