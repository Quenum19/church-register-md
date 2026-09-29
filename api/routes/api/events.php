<?php

/*
|--------------------------------------------------------------------------
| API admin — événements (contrat d'API §4 : cultes spéciaux, évangélisations)
|--------------------------------------------------------------------------
|
| Propriétaire : agent « API visiteurs ».
|
| Enregistré dans bootstrap/app.php avec le MÊME préfixe et les MÊMES middlewares que
| routes/api/admin.php : /api/admin, noms « admin. », « api », « auth:sanctum », « active »,
| « throttle:admin ». Les chemins déclarés ici sont donc relatifs à /api/admin
| (ex. Route::get('events', ...) => GET /api/admin/events).
|
| Lecture ouverte à `visitors.view` (le lien et son QR code servent à tout le dashboard),
| écriture réservée à `events.manage` (super_admin uniquement).
|
| Identifiants : ->whereNumber() => un identifiant non numérique répond 404 `not_found`.
|
*/

use App\Enums\Ability;
use App\Http\Controllers\Admin\EventController;
use Illuminate\Support\Facades\Route;

Route::get('events', [EventController::class, 'index'])
    ->can(Ability::VisitorsView->value)
    ->name('events.index');

Route::middleware('can:'.Ability::EventsManage->value)->group(function (): void {
    Route::post('events', [EventController::class, 'store'])->name('events.store');
    Route::patch('events/{event}', [EventController::class, 'update'])
        ->whereNumber('event')
        ->name('events.update');
    // 409 `event_has_visits` si des visites sont rattachées (désactiver plutôt que supprimer).
    Route::delete('events/{event}', [EventController::class, 'destroy'])
        ->whereNumber('event')
        ->name('events.destroy');
});
