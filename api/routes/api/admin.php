<?php

/*
|--------------------------------------------------------------------------
| API admin — visiteurs (contrat d'API §4 : stats, visiteurs, notes, membres, exports,
| familles, rotations, paramètres)
|--------------------------------------------------------------------------
|
| Propriétaire : agent « API visiteurs ».
|
| Enregistré dans bootstrap/app.php : préfixe /api/admin, noms « admin. », middlewares
| « api », « auth:sanctum », « active » (compte actif) et « throttle:admin » (120/min par utilisateur).
| Autorisation par ability (App\Enums\Ability, une Gate par ability), par exemple :
|   Route::get('stats', StatsController::class)->can('visitors.view');
|
| Identifiants : ->whereNumber() => un identifiant non numérique répond 404 `not_found`,
| comme un identifiant inexistant (liaison implicite de modèle).
|
*/

use App\Http\Controllers\Admin\FamilyController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\RotationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Admin\VisitorController;
use App\Http\Controllers\Admin\VisitorConversionController;
use App\Http\Controllers\Admin\VisitorExportController;
use App\Http\Controllers\Admin\VisitorNoteController;
use Illuminate\Support\Facades\Route;

// Statistiques du tableau de bord (sans enveloppe `data`, cache 5 min).
Route::get('stats', StatsController::class)->can('visitors.view')->name('stats');

// Visiteurs.
Route::get('visitors', [VisitorController::class, 'index'])->can('visitors.view')->name('visitors.index');
Route::get('visitors/{visitor}', [VisitorController::class, 'show'])
    ->whereNumber('visitor')->can('visitors.view')->name('visitors.show');
Route::patch('visitors/{visitor}', [VisitorController::class, 'update'])
    ->whereNumber('visitor')->can('visitors.update')->name('visitors.update');
Route::delete('visitors/{visitor}', [VisitorController::class, 'destroy'])
    ->whereNumber('visitor')->can('visitors.delete')->name('visitors.destroy');

// Conversion en membre / annulation.
Route::post('visitors/{visitor}/convert', [VisitorConversionController::class, 'store'])
    ->whereNumber('visitor')->can('visitors.convert')->name('visitors.convert');
Route::delete('visitors/{visitor}/convert', [VisitorConversionController::class, 'destroy'])
    ->whereNumber('visitor')->can('visitors.unconvert')->name('visitors.unconvert');

// Notes de suivi (suppression : auteur ou super_admin, App\Policies\VisitorNotePolicy).
Route::post('visitors/{visitor}/notes', [VisitorNoteController::class, 'store'])
    ->whereNumber('visitor')->can('notes.create')->name('visitors.notes.store');
Route::delete('notes/{note}', [VisitorNoteController::class, 'destroy'])
    ->whereNumber('note')->can('delete', 'note')->name('notes.destroy');

// Membres.
Route::get('members', MemberController::class)->can('visitors.view')->name('members.index');

// Exports (téléchargement direct avec le cookie de session), mêmes filtres que la liste.
Route::get('exports/visitors.csv', [VisitorExportController::class, 'csv'])
    ->can('visitors.export')->name('exports.visitors.csv');
Route::get('exports/visitors.xlsx', [VisitorExportController::class, 'xlsx'])
    ->can('visitors.export')->name('exports.visitors.xlsx');
Route::get('exports/visitors.pdf', [VisitorExportController::class, 'pdf'])
    ->can('visitors.export')->name('exports.visitors.pdf');

// Familles et rotation.
Route::get('families', FamilyController::class)->can('visitors.view')->name('families.index');
Route::get('rotations', [RotationController::class, 'index'])->can('visitors.view')->name('rotations.index');
Route::put('rotations/{year}/{month}', [RotationController::class, 'update'])
    ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{1,2}'])
    ->can('rotations.manage')
    ->name('rotations.update');

// Paramètres (sans enveloppe `data`).
Route::get('settings', [SettingsController::class, 'show'])->can('visitors.view')->name('settings.show');
Route::put('settings', [SettingsController::class, 'update'])->can('settings.update')->name('settings.update');
