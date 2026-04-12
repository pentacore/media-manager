<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ServiceConnectionController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::resource('connections', ServiceConnectionController::class)->except(['show']);
    Route::patch('connections/{connection}/toggle', [ServiceConnectionController::class, 'toggle'])
        ->name('connections.toggle');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
