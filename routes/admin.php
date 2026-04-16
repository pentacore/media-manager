<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ServiceConnectionController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'password.set', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::resource('connections', ServiceConnectionController::class)
        ->parameters(['connections' => 'serviceConnection'])
        ->except(['show']);
    Route::patch('connections/{serviceConnection}/toggle', [ServiceConnectionController::class, 'toggle'])
        ->name('connections.toggle');
    Route::post('connections/test', [ServiceConnectionController::class, 'test'])
        ->name('connections.test');
    Route::post('connections/{serviceConnection}/check-health', [ServiceConnectionController::class, 'checkHealth'])
        ->name('connections.check-health');
    Route::post('connections/{serviceConnection}/check-version', [ServiceConnectionController::class, 'checkVersion'])
        ->name('connections.check-version');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
