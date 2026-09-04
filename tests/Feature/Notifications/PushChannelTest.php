<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\AiBudgetSoftLimitReached;
use App\Notifications\DecisionAgentActed;
use App\Notifications\MediaReplacementStatusChanged;
use App\Notifications\ServiceUpdateAvailable;
use App\Notifications\ServiceWarning;
use App\Notifications\SubtitleCaseNeedsReview;
use App\Services\Notifications\PushMessage;

test('PushMessage exposes its fields and array shape', function (): void {
    $message = new PushMessage(severity: 'warning', title: 'T', body: 'B', url: 'https://mm.example.com/x');

    expect($message->severity)->toBe('warning')
        ->and($message->toArray())->toBe(['severity' => 'warning', 'title' => 'T', 'body' => 'B', 'url' => 'https://mm.example.com/x'])
        ->and(new PushMessage(severity: 'info', title: 'T', body: 'B')->url)->toBeNull();
});

test('ServiceWarning builds a push message from its severity bucket', function (): void {
    $user = User::factory()->create();
    $message = new ServiceWarning('sonarr', 'Health issue', 'Indexer down', 'disk_full')->toPush($user);

    expect($message)->toBeInstanceOf(PushMessage::class)
        ->and($message->severity)->toBe('error')
        ->and($message->title)->toBe('[sonarr] Health issue')
        ->and($message->body)->toBe('Indexer down')
        ->and($message->url)->toBe(route('monitoring.service-health'));
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
