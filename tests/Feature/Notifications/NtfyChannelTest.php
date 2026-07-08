<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\ServiceWarning;
use App\Services\Notifications\NtfyMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('routeNotificationForNtfy returns the topic or null', function (): void {
    $user = User::factory()->create(['ntfy_topic' => 'my-topic']);
    $bare = User::factory()->create();

    expect($user->routeNotificationForNtfy())->toBe('my-topic')
        ->and($bare->routeNotificationForNtfy())->toBeNull();
});

test('NtfyMessage maps severity to priority and tags', function (): void {
    expect(NtfyMessage::for('error', 'T', 'M'))->toMatchArray(['priority' => 4, 'tags' => ['rotating_light']])
        ->and(NtfyMessage::for('warning', 'T', 'M'))->toMatchArray(['priority' => 3, 'tags' => ['warning']])
        ->and(NtfyMessage::for('info', 'T', 'M'))->toMatchArray(['priority' => 2, 'tags' => ['information_source']])
        ->and(NtfyMessage::for('info', 'T', 'M', 'https://mm.example.com/x'))->toMatchArray(['click' => 'https://mm.example.com/x'])
        ->and(NtfyMessage::for('info', 'T', 'M'))->not->toHaveKey('click');
});

test('NtfyChannel publishes to the configured server with the user topic', function (): void {
    config()->set('services.ntfy.server', 'https://ntfy.example.com');
    config()->set('services.ntfy.token', 'tk_secret');
    Http::fake(['ntfy.example.com/*' => Http::response(['id' => '1'])]);

    $user = User::factory()->create(['ntfy_topic' => 'mm-alerts']);
    $notification = new ServiceWarning('sonarr', 'Health issue', 'Indexer down', 'warning');

    new NtfyChannel()->send($user, $notification);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://ntfy.example.com'
            && $request->hasHeader('Authorization', 'Bearer tk_secret')
            && $request['topic'] === 'mm-alerts'
            && $request['title'] === '[sonarr] Health issue'
            && $request['priority'] === 3;
    });
});

test('NtfyChannel skips users without a topic', function (): void {
    Http::fake();

    $user = User::factory()->create(['ntfy_topic' => null]);
    new NtfyChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));

    Http::assertNothingSent();
});

test('NtfyChannel swallows delivery failures and logs a warning', function (): void {
    config()->set('services.ntfy.server', 'https://ntfy.example.com');
    Http::fake(fn () => throw new ConnectionException('down'));
    Log::shouldReceive('warning')->once();

    $user = User::factory()->create(['ntfy_topic' => 'mm-alerts']);

    new NtfyChannel()->send($user, new ServiceWarning('sonarr', 'T', 'M', 'warning'));
})->throwsNoExceptions();
