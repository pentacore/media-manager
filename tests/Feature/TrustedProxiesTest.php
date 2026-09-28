<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('web')->get('/_test/proxy-echo', fn () => response()->json([
        'ip' => request()->ip(),
        'secure' => request()->isSecure(),
    ]));
});

/**
 * @return array{ip: string, secure: bool}
 */
function requestThroughProxy(): array
{
    return test()->call('GET', '/_test/proxy-echo', server: [
        'REMOTE_ADDR' => '172.18.0.5',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->json();
}

it('ignores forwarded headers when no trusted proxies are configured', function (): void {
    config()->set('mediamanager.trusted_proxies');

    $payload = requestThroughProxy();

    expect($payload['ip'])->toBe('172.18.0.5')
        ->and($payload['secure'])->toBeFalse();
});

it('honors forwarded headers when the proxy is inside a trusted CIDR', function (): void {
    config()->set('mediamanager.trusted_proxies', '10.0.0.0/8, 172.16.0.0/12');

    $payload = requestThroughProxy();

    expect($payload['ip'])->toBe('203.0.113.7')
        ->and($payload['secure'])->toBeTrue();
});

it('ignores forwarded headers when the proxy is outside every trusted CIDR', function (): void {
    config()->set('mediamanager.trusted_proxies', '10.0.0.0/8');

    $payload = requestThroughProxy();

    expect($payload['ip'])->toBe('172.18.0.5')
        ->and($payload['secure'])->toBeFalse();
});

it('trusts every upstream when configured with a wildcard', function (): void {
    config()->set('mediamanager.trusted_proxies', '*');

    $payload = requestThroughProxy();

    expect($payload['ip'])->toBe('203.0.113.7')
        ->and($payload['secure'])->toBeTrue();
});

test('a broad trusted proxy range logs a warning at most once a day', function (): void {
    Cache::flush();
    config()->set('mediamanager.trusted_proxies', '192.168.0.0/16');
    Log::spy();

    requestThroughProxy();
    requestThroughProxy();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'TRUSTED_PROXIES')
            && $context['broad_entries'] === ['192.168.0.0/16']);
});

test('the exact proxy address is trusted without a warning', function (): void {
    Cache::flush();
    config()->set('mediamanager.trusted_proxies', '172.18.0.5');
    Log::spy();

    $payload = requestThroughProxy();

    expect($payload['ip'])->toBe('203.0.113.7')
        ->and($payload['secure'])->toBeTrue();
    Log::shouldNotHaveReceived('warning');
});

test('an empty trusted proxy setting trusts nothing', function (): void {
    config()->set('mediamanager.trusted_proxies', '');

    $payload = requestThroughProxy();

    expect($payload['ip'])->toBe('172.18.0.5')
        ->and($payload['secure'])->toBeFalse();
});
