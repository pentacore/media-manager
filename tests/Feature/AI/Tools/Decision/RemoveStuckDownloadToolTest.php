<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Tools\Decision\RemoveStuckDownloadTool;
use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
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
    expect($result['reason'])->toBe('invalid_arguments')
        ->and($result['errors']['reason'][0])->toBe('A short reason is required so the human approver understands why.');
});

test('rejects an invalid service', function (): void {
    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'emby', 'download_id' => 'dl-1', 'reason' => 'x',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('invalid_arguments')
        ->and($result['errors']['service'][0])->toBe('service must be "sonarr" or "radarr".');
});

test('accepts the service name in any case', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'SONARR', 'download_id' => 'dl-1', 'reason' => 'Not an upgrade',
    ])), true);

    expect($result['queued'])->toBeTrue()
        ->and(ActionRequest::firstWhere('type', 'remove_stuck_download')->payload['service'])->toBe('sonarr');
});

test('describes the removal from the arr queue record with the decision agent as the reason', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
        ['downloadId' => 'dl-1', 'title' => 'Bad.Release', 'series' => ['title' => 'Severance'], 'downloadClient' => 'SABnzbd'],
    ]])]);

    (new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'Not an upgrade', 'blocklist' => true,
    ]));

    $actionRequest = ActionRequest::sole();
    expect($actionRequest->title)->toBe('Remove stuck download "Bad.Release"')
        ->and($actionRequest->description)->toBe('Proposed by the decision agent. Sonarr will remove the download from its queue and delete its data, blocklist the release.')
        ->and($actionRequest->description_verified)->toBeTrue()
        ->and($actionRequest->details)->toContain(['label' => 'Media', 'value' => 'Severance']);
});

test('removals are forced to approval even when the rule auto-executes', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => false, 'is_enabled' => true]);
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
        ['downloadId' => 'dl-1', 'title' => 'Bad.Release', 'series' => ['title' => 'Severance'], 'downloadClient' => 'SABnzbd'],
    ]])]);
    $webhookEvent = WebhookEvent::factory()->for($sonarr, 'serviceConnection')->create(['payload' => ['eventType' => 'ManualInteractionRequired', 'downloadId' => 'dl-1']]);
    bindRemovalContextForEvent($webhookEvent);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => 'Not an upgrade',
    ])), true);

    $actionRequest = ActionRequest::sole();
    expect($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeTrue()
        ->and($actionRequest->description_verified)->toBeTrue()
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Pending);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('a removal must target the download that triggered the event', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);
    $webhookEvent = WebhookEvent::factory()->for(ServiceConnection::factory()->sonarr(), 'serviceConnection')
        ->create(['payload' => ['eventType' => 'ManualInteractionRequired', 'downloadId' => 'dl-1']]);
    bindRemovalContextForEvent($webhookEvent);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-other', 'reason' => 'Not an upgrade',
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_mismatch')
        ->and(ActionRequest::count())->toBe(0);
});

test('a removal must target the triggering download when the event carries it only under downloadInfo', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);
    $webhookEvent = WebhookEvent::factory()->for(ServiceConnection::factory()->sonarr(), 'serviceConnection')
        ->create(['payload' => ['eventType' => 'ManualInteractionRequired', 'downloadInfo' => ['downloadId' => 'dl-1', 'title' => 'Some.Release']]]);
    bindRemovalContextForEvent($webhookEvent);

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-other', 'reason' => 'Not an upgrade',
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_mismatch')
        ->and(ActionRequest::count())->toBe(0);
});

test('a removal stays bound to the triggering download after the event row is trimmed', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);
    app()->instance(DecisionRunContext::class, new DecisionRunContext(
        webhookEventId: null,
        maxActions: 3,
        sourceService: 'sonarr',
        eventPayload: ['eventType' => 'ManualInteractionRequired', 'downloadId' => 'dl-1'],
        originConnectionId: ServiceConnection::factory()->sonarr()->create()->id,
    ));

    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-other', 'reason' => 'Not an upgrade',
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_mismatch')
        ->and(ActionRequest::count())->toBe(0);
});

/**
 * Binds a context the way RunDecisionAgent builds it for a persisted event.
 */
function bindRemovalContextForEvent(WebhookEvent $webhookEvent): void
{
    app()->instance(DecisionRunContext::class, new DecisionRunContext(
        webhookEventId: $webhookEvent->id,
        maxActions: 3,
        sourceService: 'sonarr',
        eventPayload: $webhookEvent->payload,
        originConnectionId: $webhookEvent->service_connection_id,
    ));
}

test("a non-string argument is refused in the tool's own words", function (array $arguments, string $field, string $message): void {
    $result = json_decode((new RemoveStuckDownloadTool)->handle(new Request($arguments)), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('invalid_arguments')
        ->and($result['errors'][$field][0])->toBe($message);
    expect(ActionRequest::count())->toBe(0);
})->with([
    'a numeric service' => [['service' => 5, 'download_id' => 'dl-1', 'reason' => 'x'], 'service', 'service must be "sonarr" or "radarr".'],
    'a list as the download id' => [['service' => 'sonarr', 'download_id' => ['dl-1'], 'reason' => 'x'], 'download_id', 'download_id must be a string.'],
    'a list as the reason' => [['service' => 'sonarr', 'download_id' => 'dl-1', 'reason' => ['x']], 'reason', 'reason must be a short plain-text explanation for the human approver.'],
]);
