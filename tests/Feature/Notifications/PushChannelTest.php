<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\Channels\PushChannel;
use App\Notifications\DecisionAgentActed;
use App\Notifications\MediaReplacementStatusChanged;
use App\Notifications\ServiceUpdateAvailable;
use App\Notifications\ServiceWarning;
use App\Notifications\SubtitleCaseNeedsReview;
use App\Services\Notifications\PushMessage;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

test('PushMessage exposes its fields and array shape', function (): void {
    $message = new PushMessage(severity: 'warning', title: 'T', body: 'B', url: 'https://mm.example.com/x');

    expect($message->severity)->toBe('warning')
        ->and($message->toArray())->toBe(['severity' => 'warning', 'title' => 'T', 'body' => 'B', 'url' => 'https://mm.example.com/x'])
        ->and(new PushMessage(severity: 'info', title: 'T', body: 'B')->url)->toBeNull();
});

test('ServiceWarning builds a push message from its severity bucket', function (): void {
    $user = User::factory()->create();
    $pushMessage = new ServiceWarning('sonarr', 'Health issue', 'Indexer down', 'disk_full')->toPush($user);

    expect($pushMessage)->toBeInstanceOf(PushMessage::class)
        ->and($pushMessage->severity)->toBe('error')
        ->and($pushMessage->title)->toBe('[sonarr] Health issue')
        ->and($pushMessage->body)->toBe('Indexer down')
        ->and($pushMessage->url)->toBe(route('monitoring.service-health'));
});

test('every catalog notification returns a PushMessage', function (): void {
    $user = User::factory()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $messages = [
        new AiBudgetSoftLimitReached(12.5, 10.0)->toPush($user),
        new DecisionAgentActed(disposition: 'queued', actionCount: 1, summary: 'S', eventLabel: 'sonarr Grab')->toPush($user),
        new MediaReplacementStatusChanged(service: 'sonarr', title: 'T', message: 'M', level: 'warning')->toPush($user),
        new ServiceUpdateAvailable($connection, '4.1.0', '4.0.0')->toPush($user),
        new SubtitleCaseNeedsReview(subtitleCaseId: 1, displayName: 'Show S01E01', summary: 'S', category: 'c')->toPush($user),
    ];

    foreach ($messages as $message) {
        expect($message)->toBeInstanceOf(PushMessage::class)
            ->and($message->title)->not->toBe('');
    }
});

/**
 * Records what send() hands to deliver() so the base-class branches can be
 * asserted without a real transport.
 */
abstract class RecordingPushChannel extends PushChannel
{
    /** @var list<array{route: mixed, message: PushMessage}> */
    public array $delivered = [];

    public function deliver(mixed $route, PushMessage $message): void
    {
        $this->delivered[] = ['route' => $route, 'message' => $message];
    }

    public function label(): string
    {
        return 'Recording';
    }
}

class StubPushChannel extends RecordingPushChannel
{
    public const string DRIVER = 'stub';
}

/** A subclass that forgot to override DRIVER, so it inherits the empty default. */
class DriverlessPushChannel extends RecordingPushChannel {}

class StubPushNotification extends Notification
{
    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(severity: 'info', title: 'Stub', body: 'Body');
    }
}

class PushlessNotification extends Notification {}

class StubPushNotifiable
{
    use Notifiable;

    public function __construct(private readonly mixed $route = 'stub-route') {}

    public function routeNotificationForStub(): mixed
    {
        return $this->route;
    }
}

test('send() no-ops when the channel inherits the empty DRIVER default', function (): void {
    $channel = new DriverlessPushChannel;

    $channel->send(new StubPushNotifiable, new StubPushNotification);

    expect($channel->delivered)->toBe([]);
});

test('send() skips notifications that cannot build a push message', function (): void {
    $channel = new StubPushChannel;

    $channel->send(new StubPushNotifiable, new PushlessNotification);

    expect($channel->delivered)->toBe([]);
});

test('send() skips empty routes', function (mixed $route): void {
    $channel = new StubPushChannel;

    $channel->send(new StubPushNotifiable($route), new StubPushNotification);

    expect($channel->delivered)->toBe([]);
})->with([null, '', [[]]]);

test('send() hands the resolved route and the push message to deliver()', function (): void {
    $channel = new StubPushChannel;

    $channel->send(new StubPushNotifiable('mm-alerts'), new StubPushNotification);

    expect($channel->delivered)->toHaveCount(1)
        ->and($channel->delivered[0]['route'])->toBe('mm-alerts')
        ->and($channel->delivered[0]['message'])->toBeInstanceOf(PushMessage::class)
        ->and($channel->delivered[0]['message']->title)->toBe('Stub');
});

test('send() swallows and logs a deliver() failure', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => $message === 'Recording delivery failed');

    $channel = new class extends RecordingPushChannel
    {
        public const string DRIVER = 'stub';

        public function deliver(mixed $route, PushMessage $message): void
        {
            throw new RuntimeException('transport down');
        }
    };

    $channel->send(new StubPushNotifiable('mm-alerts'), new StubPushNotification);

    expect($channel->delivered)->toBe([]);
});
