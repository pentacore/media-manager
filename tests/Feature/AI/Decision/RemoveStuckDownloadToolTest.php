<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Decision\RemoveStuckDownloadTool;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Queue::fake();
    resolve(DecisionAgentSettings::class)->setAllowManualImport(true);
    app()->instance(DecisionRunContext::class, new DecisionRunContext(null, 3, 'sonarr'));
});

afterEach(function (): void {
    app()->forgetInstance(DecisionRunContext::class);
});

test('refuses when the manual-import capability is disabled', function (): void {
    resolve(DecisionAgentSettings::class)->setAllowManualImport(false);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'not an upgrade',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('capability_disabled');
    expect(ActionRequest::count())->toBe(0);
});

test('queues a remove_stuck_download tagged as agent with the reason', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'Not an upgrade for existing episode file(s)',
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['requires_approval'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'remove_stuck_download');
    expect($request->origin)->toBe('agent');
    expect($request->payload['service'])->toBe('sonarr');
    expect($request->payload['download_id'])->toBe('dl-1');
    expect($request->payload['agent_rationale'])->toContain('Not an upgrade');
});

test('passes blocklist true through to the payload', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'Fake release', 'blocklist' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'remove_stuck_download');
    expect($request->payload['blocklist'])->toBeTrue();
});

test('blocklist defaults to false when omitted', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'Not an upgrade',
    ])), true);

    expect($result['queued'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'remove_stuck_download');
    expect($request->payload['blocklist'])->toBeFalse();
});

test('passes search_replacement true through to the payload', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'Blocklisted, retry with another release', 'search_replacement' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'remove_stuck_download');
    expect($request->payload['search_replacement'])->toBeTrue();
});

test('search_replacement defaults to false when omitted', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'Not an upgrade',
    ])), true);

    expect($result['queued'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'remove_stuck_download');
    expect($request->payload['search_replacement'])->toBeFalse();
});

test('requires a reason', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => '',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('missing_reason');
});

test('rejects an invalid service', function (): void {
    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'emby', 'download_id' => 'dl-1', 'reason' => 'x',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('invalid_service');
});
