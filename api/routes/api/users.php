<?php

/*
|--------------------------------------------------------------------------
| API admin — administrateurs et journal d'audit (contrat d'API §4)
|--------------------------------------------------------------------------
|
| Propriétaire : agent « authentification et administrateurs ».
|
| Même groupe que routes/api/admin.php : préfixe /api/admin, noms « admin. »,
| « api », « auth:sanctum », « active », « throttle:admin ».
|
*/

use App\Enums\Ability;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:'.Ability::UsersManage->value)->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
    Route::post('users/{user}/invitation', [UserController::class, 'resendInvitation'])
        ->whereNumber('user')
        ->name('users.invitation');
});

Route::get('audit-logs', AuditLogController::class)
    ->middleware('can:'.Ability::AuditView->value)
    ->name('audit-logs.index');
