<?php

/*
|--------------------------------------------------------------------------
| API publique — parcours visiteur (contrat d'API §2)
|--------------------------------------------------------------------------
|
| Propriétaire : agent « API publique ».
|
| Enregistré dans bootstrap/app.php : préfixe /api/public, noms « public. », groupe de
| middlewares « public » (HideRateLimitHeaders + throttle:public = 300 requêtes/min par IP
| + LimitPublicPayload + SubstituteBindings).
| Groupe SANS session, SANS cookie, SANS CSRF : ne jamais y ajouter « web », « api » ni « auth ».
|
| Limites supplémentaires pour l'identification :
|   - par IP : Route::post('identify', ...)->middleware('throttle:identify') (200/heure par défaut,
|     variable RATE_LIMIT_IDENTIFY_PER_IP) ;
|   - par numéro : 5 JETONS émis par heure, comptés par App\Services\Journey\IdentificationService
|     (clé = HMAC du numéro E.164, voir App\Support\RateLimits::identifyNumberKey()).
|
| Aucune réponse de ce fichier ne contient de donnée personnelle.
|
*/

use App\Http\Controllers\Public\ConfigController;
use App\Http\Controllers\Public\IdentifyController;
use App\Http\Controllers\Public\StoreVisitController;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Route;

Route::get('config', ConfigController::class)->name('config');

Route::post('identify', IdentifyController::class)
    ->middleware('throttle:'.RateLimits::IDENTIFY)
    ->name('identify');

Route::post('visits', StoreVisitController::class)->name('visits.store');
