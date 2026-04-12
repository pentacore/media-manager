<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['auth', 'role:admin'])->get('/test-admin', fn (): string => 'ok');
    Route::middleware(['auth', 'role:member'])->get('/test-member', fn (): string => 'ok');
});

test('admin can access admin routes', function (): void {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/test-admin')
        ->assertOk();
});

test('member cannot access admin routes', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get('/test-admin')
        ->assertForbidden();
});

test('viewer cannot access member routes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/test-member')
        ->assertForbidden();
});

test('member can access member routes', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get('/test-member')
        ->assertOk();
});

test('admin can access member routes', function (): void {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/test-member')
        ->assertOk();
});
