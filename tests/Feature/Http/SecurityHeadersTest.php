<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/_test/own-csp', fn () => response('attachment')
        ->header('Content-Security-Policy', "default-src 'none'; sandbox")
        ->header('X-Frame-Options', 'DENY'));
});

test('responses carry the baseline security headers', function (): void {
    $this->get('/up')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('api responses carry the headers too', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['webhook_token' => 'secret']);

    $this->postJson(route('webhooks.handle', ['service' => 'sonarr', 'connection' => $connection->id]), ['eventType' => 'Test'], ['X-Webhook-Token' => 'secret'])
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('a header the response already set is kept', function (): void {
    $this->get('/_test/own-csp')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('hsts is sent on secure requests when enabled', function (): void {
    config()->set('mediamanager.security.hsts_enabled', true);

    $this->get('https://localhost/up')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('hsts is never sent over plain http', function (): void {
    config()->set('mediamanager.security.hsts_enabled', true);

    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

test('hsts stays off by default even on secure requests', function (): void {
    $this->get('https://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});
