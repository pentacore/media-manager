<?php

declare(strict_types=1);

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
    config()->set('mediamanager.trusted_proxies', null);

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
