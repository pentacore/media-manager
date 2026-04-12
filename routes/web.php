<?php

use App\Http\Controllers\Auth\AuthentikController;
use App\Http\Controllers\Auth\EmbyAuthController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware('guest')->group(function (): void {
    Route::get('auth/authentik', [AuthentikController::class, 'redirect'])->name('auth.authentik');
    Route::get('auth/authentik/callback', [AuthentikController::class, 'callback'])->name('auth.authentik.callback');
    Route::post('auth/emby', [EmbyAuthController::class, 'store'])->name('auth.emby');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
