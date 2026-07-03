<?php

use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\UserPreferencesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])
        ->name('settings.notifications.edit');
    Route::put('settings/notifications', [NotificationPreferencesController::class, 'update'])
        ->name('settings.notifications.update');

    Route::get('settings/preferences', [UserPreferencesController::class, 'edit'])
        ->name('settings.preferences.edit');
    Route::put('settings/preferences', [UserPreferencesController::class, 'update'])
        ->name('settings.preferences.update');
});
