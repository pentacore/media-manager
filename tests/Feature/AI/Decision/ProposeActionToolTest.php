<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Decision\ProposeActionTool;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Queue::fake();
});

function bindDecisionContext(int $maxActions = 3, ?int $webhookEventId = null): DecisionRunContext
{
    $context = new DecisionRunContext($webhookEventId, $maxActions, 'sonarr');
    app()->instance(DecisionRunContext::class, $context);

    return $context;
}

afterEach(function (): void {
    app()->forgetInstance(DecisionRunContext::class);
});

test('queues an ActionRequest tagged as agent with the rationale', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    $decisionRunContext = bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'delete_series',
        'target_service' => 'sonarr',
        'rationale' => 'Unmonitored and unwatched.',
        'payload' => ['series_id' => 7],
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['requires_approval'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'delete_series');
    expect($request->origin)->toBe('agent');
    expect($request->source_service)->toBe('sonarr');
    expect($request->payload['series_id'])->toBe(7);
    expect($request->payload['agent_rationale'])->toBe('Unmonitored and unwatched.');
    expect($decisionRunContext->count())->toBe(1);
});

test('rejects action types outside the allowlist', function (): void {
    bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'rm_minus_rf',
        'target_service' => 'sonarr',
        'rationale' => 'nope',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('type_not_allowed');
    expect(ActionRequest::count())->toBe(0);
});

test('requires a rationale', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);
    bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'emby_library_scan',
        'target_service' => 'emby',
        'rationale' => '',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('missing_rationale');
});

test('enforces the per-run action cap', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => true]);
    bindDecisionContext(maxActions: 1);

    $first = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'emby_library_scan', 'target_service' => 'emby', 'rationale' => 'one',
    ])), true);
    $second = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'emby_library_scan', 'target_service' => 'emby', 'rationale' => 'two',
    ])), true);

    expect($first['queued'])->toBeTrue();
    expect($second['queued'])->toBeFalse();
    expect($second['reason'])->toBe('max_actions_reached');
    expect(ActionRequest::count())->toBe(1);
});

test('reports no_action_type_config when the rule is missing', function (): void {
    bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'delete_movie', 'target_service' => 'radarr', 'rationale' => 'x',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('no_action_type_config');
});

test('refuses to run without an active decision context', function (): void {
    app()->forgetInstance(DecisionRunContext::class);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'emby_library_scan', 'target_service' => 'emby', 'rationale' => 'x',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('no_active_run');
});
