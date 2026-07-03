<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Settings\AiSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Queue::fake();
    $this->seed(ActionTypeConfigSeeder::class);
    resolve(AiSettings::class)->withMode(AiMode::Executive);
});

test('risk is Destructive', function (): void {
    expect((new RemoveStuckDownloadChatTool)->risk())->toBe(Risk::Destructive);
});

test('queues a remove_stuck_download action request with rationale', function (): void {
    $result = json_decode(
        (new RemoveStuckDownloadChatTool)->handle(new Request([
            'service' => 'radarr',
            'download_id' => 'HASH-B',
            'reason' => 'Not an upgrade for existing file.',
        ])),
        true,
    );

    expect($result['queued'])->toBeTrue();

    $actionRequest = ActionRequest::findOrFail($result['action_request_id']);
    expect($actionRequest->type)->toBe('remove_stuck_download')
        ->and($actionRequest->target_service)->toBe('radarr')
        ->and($actionRequest->payload['download_id'])->toBe('HASH-B')
        ->and($actionRequest->payload['agent_rationale'])->toBe('Not an upgrade for existing file.');
});

test('blocklist arg lands true in the payload', function (): void {
    $result = json_decode(
        (new RemoveStuckDownloadChatTool)->handle(new Request([
            'service' => 'radarr',
            'download_id' => 'HASH-B',
            'reason' => 'Corrupt release.',
            'blocklist' => true,
        ])),
        true,
    );

    expect($result['queued'])->toBeTrue();

    $actionRequest = ActionRequest::findOrFail($result['action_request_id']);
    expect($actionRequest->payload['blocklist'])->toBeTrue();
});

test('blocklist defaults to false when omitted', function (): void {
    $result = json_decode(
        (new RemoveStuckDownloadChatTool)->handle(new Request([
            'service' => 'radarr',
            'download_id' => 'HASH-B',
            'reason' => 'Not an upgrade for existing file.',
        ])),
        true,
    );

    expect($result['queued'])->toBeTrue();

    $actionRequest = ActionRequest::findOrFail($result['action_request_id']);
    expect($actionRequest->payload['blocklist'])->toBeFalse();
});

test('missing reason fails without queueing', function (): void {
    $result = json_decode(
        (new RemoveStuckDownloadChatTool)->handle(new Request([
            'service' => 'radarr',
            'download_id' => 'HASH-B',
        ])),
        true,
    );

    expect($result['error'])->toBe('tool_failed')
        ->and(ActionRequest::count())->toBe(0);
});
