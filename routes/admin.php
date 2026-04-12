<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ServiceConnectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::resource('connections', ServiceConnectionController::class)->except(['show']);
    Route::patch('connections/{connection}/toggle', [ServiceConnectionController::class, 'toggle'])
        ->name('connections.toggle');
});
