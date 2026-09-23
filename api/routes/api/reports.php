<?php

/*
|--------------------------------------------------------------------------
| Rapports mensuels et destinataires (contrat d'API §4 : /reports, /report-recipients)
|--------------------------------------------------------------------------
|
| Propriétaire : agent « rapports ».
|
| Enregistré dans bootstrap/app.php avec le MÊME préfixe et les MÊMES middlewares que
| routes/api/admin.php : /api/admin, noms « admin. », « api », « auth:sanctum », « active »,
| « throttle:admin ». Les chemins déclarés ici sont donc relatifs à /api/admin
| (ex. Route::get('reports', ...) => GET /api/admin/reports).
|
*/

use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportRecipientController;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Route;

// Rapports mensuels. Mois futur, invalide ou hors des années disponibles => 404 not_found.
Route::get('reports', [ReportController::class, 'index'])
    ->can('visitors.view')
    ->name('reports.index');

Route::get('reports/{year}/{month}', [ReportController::class, 'show'])
    ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{1,2}'])
    ->can('visitors.view')
    ->name('reports.show');

Route::post('reports/{year}/{month}/send', [ReportController::class, 'send'])
    ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{1,2}'])
    ->can('reports.send')
    ->name('reports.send');

// Destinataires des rapports (family_id NULL = reçoit tous les rapports).
Route::controller(ReportRecipientController::class)
    ->middleware('can:recipients.manage')
    ->prefix('report-recipients')
    ->name('report-recipients.')
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        // E-mail de test : limite dédiée (5/heure par utilisateur). La limite « admin »
        // (120/min) laisserait un compte légitime, ou détourné, transformer l'API en
        // relais d'envoi vers n'importe quelle adresse.
        Route::post('test', 'test')
            ->middleware('throttle:'.RateLimits::REPORT_TEST)
            ->name('test');
        Route::patch('{recipient}', 'update')->whereNumber('recipient')->name('update');
        Route::delete('{recipient}', 'destroy')->whereNumber('recipient')->name('destroy');
    });
