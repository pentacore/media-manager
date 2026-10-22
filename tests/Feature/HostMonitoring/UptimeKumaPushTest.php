<?php

declare(strict_types=1);

use App\Services\HostMonitoring\UptimeKumaPusher;
use App\Settings\MonitoringSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.monitoring.uptime_kuma_push_url');
    Http::preventStrayRequests();
});

test('the push hits the configured url with status up', function (): void {
    resolve(MonitoringSettings::class)->setUptimeKumaPushUrl('https://kuma.test/api/push/abc123?status=up&msg=OK&ping=');
    Http::fake(['kuma.test/*' => Http::response(['ok' => true])]);

    $this->artisan('monitoring:push-heartbeat')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://kuma.test/api/push/abc123?status=up&msg=OK');
});

test('the config default is used when nothing is stored', function (): void {
    config()->set('mediamanager.monitoring.uptime_kuma_push_url', 'https://kuma.test/api/push/fromenv');
    Http::fake(['kuma.test/*' => Http::response(['ok' => true])]);

    expect(resolve(UptimeKumaPusher::class)->push())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://kuma.test/api/push/fromenv?status=up&msg=OK');
});

test('without a url nothing is sent', function (): void {
    Http::fake();

    $this->artisan('monitoring:push-heartbeat')->assertSuccessful();
    expect(resolve(UptimeKumaPusher::class)->push())->toBeFalse();

    Http::assertNothingSent();
});

test('an empty stored or configured url counts as unset', function (): void {
    config()->set('mediamanager.monitoring.uptime_kuma_push_url', '');
    Http::fake();

    expect(resolve(MonitoringSettings::class)->uptimeKumaPushUrl())->toBeNull()
        ->and(resolve(UptimeKumaPusher::class)->push())->toBeFalse();

    Http::assertNothingSent();
});

test('a failing push is logged without the url and never throws', function (Closure $fake): void {
    Log::spy();
    resolve(MonitoringSettings::class)->setUptimeKumaPushUrl('https://kuma.test/api/push/abc123');
    Http::fake(['kuma.test/*' => $fake]);

    $this->artisan('monitoring:push-heartbeat')->assertSuccessful();
    expect(resolve(UptimeKumaPusher::class)->push())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'UptimeKumaPusher: push failed'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'abc123')
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'kuma.test'))
        ->twice();
})->with([
    'a server error' => [fn () => Http::response('nope', 500)],
    'a connection failure' => [function (): never {
        throw new ConnectionException('cURL error 7: could not connect to https://kuma.test/api/push/abc123');
    }],
]);

test('the push url hint shows only the last four characters', function (): void {
    $monitoringSettings = resolve(MonitoringSettings::class);

    expect($monitoringSettings->pushUrlHint())->toBeNull();

    $monitoringSettings->setUptimeKumaPushUrl('https://kuma.test/api/push/abc123XYZ9');

    expect($monitoringSettings->pushUrlHint())->toBe('…XYZ9');
});

test('clearing the stored url falls back to config', function (): void {
    config()->set('mediamanager.monitoring.uptime_kuma_push_url', 'https://kuma.test/api/push/fromenv');
    $monitoringSettings = resolve(MonitoringSettings::class);
    $monitoringSettings->setUptimeKumaPushUrl('https://kuma.test/api/push/stored');

    expect($monitoringSettings->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/stored');

    $monitoringSettings->setUptimeKumaPushUrl(null);

    expect($monitoringSettings->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/fromenv');
});

test('the push is scheduled every minute without overlapping', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'monitoring:push-heartbeat'));

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
