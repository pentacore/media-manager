<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthentikController;
use App\Http\Controllers\Auth\EmbyAuthController;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified', 'password.set'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

Route::middleware('guest')->group(function (): void {
    Route::get('auth/authentik', [AuthentikController::class, 'redirect'])->name('auth.authentik');
    Route::get('auth/authentik/callback', [AuthentikController::class, 'callback'])->name('auth.authentik.callback');
    Route::post('auth/emby', [EmbyAuthController::class, 'store'])
        ->middleware('throttle:emby-login')
        ->name('auth.emby');
});

Route::get('invite/{user}/accept', [InviteController::class, 'accept'])->name('auth.invite.accept');
Route::get('set-password', [InviteController::class, 'showSetPassword'])->name('auth.set-password');
Route::post('set-password', [InviteController::class, 'setPassword'])->name('auth.set-password.store');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/media.php';
require __DIR__.'/emby.php';
require __DIR__.'/actions.php';
require __DIR__.'/ai.php';
