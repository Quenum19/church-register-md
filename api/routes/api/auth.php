<?php

/*
|--------------------------------------------------------------------------
| Authentification admin (contrat d'API §3)
|--------------------------------------------------------------------------
|
| Propriétaire : agent « authentification et administrateurs ».
|
| Enregistré dans bootstrap/app.php : préfixe /api/auth, noms « auth. », groupe « api »
| (Sanctum SPA : session en base + CSRF pour les requêtes venant de SANCTUM_STATEFUL_DOMAINS).
|
| - Routes invitées : login et challenge 2FA (limitation des SEULS échecs, manuelle, voir
|   App\Services\Auth\LoginThrottle) ; forgot / reset (10 requêtes / 15 min par IP, en plus
|   du délai d'une minute entre deux e-mails imposé par le broker).
| - Routes authentifiées : auth:sanctum + compte actif + 120 requêtes / min par utilisateur.
|
*/

use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Support\RateLimits;
use Illuminate\Support\Facades\Route;

Route::post('login', LoginController::class)->name('login');
Route::post('two-factor/challenge', TwoFactorChallengeController::class)->name('two-factor.challenge');

Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:10,15,forgot-password')
    ->name('password.forgot');
Route::post('reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:10,15,reset-password')
    ->name('password.reset');

Route::middleware(['auth:sanctum', 'active', 'throttle:'.RateLimits::ADMIN])->group(function (): void {
    Route::get('me', [SessionController::class, 'show'])->name('me');
    Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

    Route::patch('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
    Route::put('password', [AccountController::class, 'updatePassword'])->name('password.update');

    Route::post('two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::delete('two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
});
