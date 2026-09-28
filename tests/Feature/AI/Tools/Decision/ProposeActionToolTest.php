<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Enums\ActionRequestStatus;
use App\Enums\MediaReplacementStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
});

/**
 * Binds a context the way RunDecisionAgent builds it for a persisted event:
 * the event id plus its payload and connection snapshot.
 */
function bindDecisionContext(int $maxActions = 3, ?int $webhookEventId = null): DecisionRunContext
{
    $webhookEvent = $webhookEventId === null ? null : WebhookEvent::query()->findOrFail($webhookEventId);
    $context = new DecisionRunContext(
        webhookEventId: $webhookEventId,
        maxActions: $maxActions,
        sourceService: 'sonarr',
        eventPayload: $webhookEvent->payload ?? [],
        originConnectionId: $webhookEvent?->service_connection_id,
    );
    app()->instance(DecisionRunContext::class, $context);

    return $context;
}

afterEach(function (): void {
    app()->forgetInstance(DecisionRunContext::class);
});

test('queues an ActionRequest tagged as agent with the rationale', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'requires_approval' => true, 'is_enabled' => true]);
    $webhookEvent = WebhookEvent::factory()->for(ServiceConnection::factory()->sonarr(), 'serviceConnection')->create(['payload' => ['series' => ['id' => 7]]]);
    $decisionRunContext = bindDecisionContext(webhookEventId: $webhookEvent->id);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'delete_series',
        'target_service' => 'sonarr',
        'rationale' => 'Unmonitored and unwatched.',
        'payload' => ['sonarr_series_id' => 7],
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['requires_approval'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'delete_series');
    expect($request->origin)->toBe('agent');
    expect($request->source_service)->toBe('sonarr');
    expect($request->payload['sonarr_series_id'])->toBe(7);
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
    expect($result['reason'])->toBe('invalid_arguments')
        ->and($result['errors']['rationale'][0])->toBe('A plain-English rationale is required so a human can understand the proposal.');
});

test('seerr request mutations are forced to approval even when the type auto-executes', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'approve_seerr_request', 'requires_approval' => false, 'is_enabled' => true]);
    $connection = ServiceConnection::factory()->seerr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'payload' => ['notification_type' => 'MEDIA_PENDING', 'request' => ['request_id' => 42]],
    ]);
    bindDecisionContext(webhookEventId: $event->id);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'approve_seerr_request',
        'target_service' => 'seerr',
        'rationale' => 'Matches auto-approve rules.',
        'payload' => ['seerr_request_id' => 42],
    ])), true);

    expect($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeTrue()
        ->and(ActionRequest::firstWhere('type', 'approve_seerr_request')->requires_approval)->toBeTrue();
});

test('seerr request mutations must target the triggering request id', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'decline_seerr_request', 'requires_approval' => true, 'is_enabled' => true]);
    $connection = ServiceConnection::factory()->seerr()->create();
    $event = WebhookEvent::factory()->create([
        'service_connection_id' => $connection->id,
        'payload' => ['notification_type' => 'MEDIA_PENDING', 'request' => ['request_id' => 42]],
    ]);
    bindDecisionContext(webhookEventId: $event->id);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'decline_seerr_request',
        'target_service' => 'seerr',
        'rationale' => 'Decline the other request.',
        'payload' => ['seerr_request_id' => 999],
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_mismatch')
        ->and(ActionRequest::count())->toBe(0);
});

test('seerr request mutations are refused when the trigger has no request id', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'cleanup_seerr_request', 'requires_approval' => true, 'is_enabled' => true]);
    bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'cleanup_seerr_request',
        'target_service' => 'seerr',
        'rationale' => 'Cleanup.',
        'payload' => ['seerr_request_id' => 5],
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_not_verifiable')
        ->and(ActionRequest::count())->toBe(0);
});

test('monitor proposals are refused while a replacement is in flight for the target', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'requires_approval' => false, 'is_enabled' => true]);
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'target' => ['service' => 'sonarr', 'series_id' => 42, 'episode_file_ids' => [501]],
    ]);
    bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'monitor_series',
        'target_service' => 'sonarr',
        'rationale' => 'Series became unmonitored.',
        'payload' => ['series_id' => 42, 'monitored' => true],
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('replacement_in_flight')
        ->and(ActionRequest::where('type', 'monitor_series')->count())->toBe(0);
});

test('monitor proposals for unrelated targets pass the replacement guard', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'requires_approval' => true, 'is_enabled' => true]);
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'target' => ['service' => 'sonarr', 'series_id' => 42, 'episode_file_ids' => [501]],
    ]);
    bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'monitor_series',
        'target_service' => 'sonarr',
        'rationale' => 'Different series.',
        'payload' => ['series_id' => 7, 'monitored' => true],
    ])), true);

    expect($result['queued'])->toBeTrue();
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
    $webhookEvent = WebhookEvent::factory()->for(ServiceConnection::factory()->radarr(), 'serviceConnection')->create(['payload' => ['movie' => ['id' => 9]]]);
    bindDecisionContext(webhookEventId: $webhookEvent->id);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'delete_movie', 'target_service' => 'radarr', 'rationale' => 'x', 'payload' => ['radarr_movie_id' => 9],
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

test('a proposal is described from the server-resolved target with the triggering event as the reason', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'is_enabled' => true, 'requires_approval' => true]);
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'name' => 'Sonarr']);
    IndexedSeries::factory()->for($sonarr, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance', 'year' => 2022]);
    $webhookEvent = WebhookEvent::factory()->for($sonarr, 'serviceConnection')->create(['event_type' => 'SeriesDelete', 'payload' => ['series' => ['id' => 142]]]);
    bindDecisionContext(webhookEventId: $webhookEvent->id);

    (new ProposeActionTool)->handle(new Request([
        'type' => 'delete_series',
        'target_service' => 'sonarr',
        'rationale' => 'The series was removed upstream.',
        'payload' => ['sonarr_series_id' => 142, 'delete_files' => true],
        'title' => 'Something Else',
    ]));

    $actionRequest = ActionRequest::sole();
    expect($actionRequest->title)->toBe('Delete series "Severance (2022)"')
        ->and($actionRequest->description)->toBe('Proposed by the decision agent in response to a "SeriesDelete" event from Sonarr. Sonarr will delete the series and its files from disk.')
        ->and($actionRequest->description_verified)->toBeTrue()
        ->and($actionRequest->payload['agent_rationale'])->toBe('The series was removed upstream.');
});

test('a proposal without its target id is rejected as missing_target', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'is_enabled' => true, 'requires_approval' => true]);
    app()->instance(DecisionRunContext::class, new DecisionRunContext(webhookEventId: null, maxActions: 3));

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'delete_series',
        'target_service' => 'sonarr',
        'rationale' => 'Remove it.',
        'payload' => ['series_id' => 142],
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('missing_target')
        ->and($result['message'])->toContain('sonarr_series_id')
        ->and(ActionRequest::count())->toBe(0);
});

test('a proposal the server cannot resolve falls back to the model title and waits for approval', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'is_enabled' => true, 'requires_approval' => false]);
    bindDecisionContext();

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'monitor_series',
        'target_service' => 'sonarr',
        'rationale' => 'Monitor it.',
        'payload' => ['series_id' => 142, 'monitored' => true],
        'title' => 'Severance',
    ])), true);

    $actionRequest = ActionRequest::sole();
    expect($result['requires_approval'])->toBeTrue()
        ->and($actionRequest->title)->toBe('Monitor series "Severance"')
        ->and($actionRequest->description)->toStartWith('Proposed by the decision agent. ')
        ->and($actionRequest->description_verified)->toBeFalse()
        ->and($actionRequest->requires_approval)->toBeTrue();
});

test('the payload schema example uses the delete_series target key the server requires', function (): void {
    $schema = resolve(ProposeActionTool::class)->schema(new JsonSchemaTypeFactory);

    expect($schema['payload']->toArray()['description'])
        ->toContain('"sonarr_series_id": 42')
        ->not->toContain('{"series_id"');
});

/**
 * @param  array<string, mixed>  $payload
 */
function bindDecisionContextForEvent(ServiceConnection $serviceConnection, array $payload): WebhookEvent
{
    $webhookEvent = WebhookEvent::factory()->for($serviceConnection, 'serviceConnection')->create(['payload' => $payload]);
    bindDecisionContext(webhookEventId: $webhookEvent->id);

    return $webhookEvent;
}

test('agent delete proposals are forced to approval even when the rule auto-executes', function (string $type, string $idKey, string $subjectKey): void {
    ActionTypeConfig::factory()->create(['type' => $type, 'requires_approval' => false, 'is_enabled' => true]);
    $connection = $subjectKey === 'series'
        ? ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989'])
        : ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);
    $subjectKey === 'series'
        ? IndexedSeries::factory()->for($connection, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance'])
        : IndexedMovie::factory()->for($connection, 'serviceConnection')->create(['radarr_id' => 142, 'title' => 'Heat']);
    bindDecisionContextForEvent($connection, [$subjectKey => ['id' => 142]]);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => $type,
        'target_service' => $subjectKey === 'series' ? 'sonarr' : 'radarr',
        'rationale' => 'Removed upstream.',
        'payload' => [$idKey => 142],
    ])), true);

    $actionRequest = ActionRequest::sole();
    expect($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeTrue()
        ->and($actionRequest->description_verified)->toBeTrue()
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Pending);
    Queue::assertNotPushed(ExecuteActionRequest::class);
})->with([
    'delete_series' => ['delete_series', 'sonarr_series_id', 'series'],
    'delete_movie' => ['delete_movie', 'radarr_movie_id', 'movie'],
]);

test('series and movie proposals must target the subject of the triggering event', function (string $type, array $payload, string $subjectKey): void {
    ActionTypeConfig::factory()->create(['type' => $type, 'requires_approval' => true, 'is_enabled' => true]);
    $connection = $subjectKey === 'series' ? ServiceConnection::factory()->sonarr()->create() : ServiceConnection::factory()->radarr()->create();
    bindDecisionContextForEvent($connection, [$subjectKey => ['id' => 42]]);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => $type,
        'target_service' => $subjectKey === 'series' ? 'sonarr' : 'radarr',
        'rationale' => 'Act on a different title.',
        'payload' => $payload,
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_mismatch')
        ->and(ActionRequest::count())->toBe(0);
})->with([
    'delete_series' => ['delete_series', ['sonarr_series_id' => 999, 'delete_files' => true], 'series'],
    'monitor_series' => ['monitor_series', ['series_id' => 999, 'monitored' => false], 'series'],
    'set_series_quality_profile' => ['set_series_quality_profile', ['series_id' => 999, 'quality_profile_id' => 4], 'series'],
    'delete_movie' => ['delete_movie', ['radarr_movie_id' => 999], 'movie'],
    'monitor_movie' => ['monitor_movie', ['movie_id' => 999, 'monitored' => false], 'movie'],
    'set_movie_quality_profile' => ['set_movie_quality_profile', ['movie_id' => 999, 'quality_profile_id' => 4], 'movie'],
    'monitor_series smuggling the matching id under the key its executor ignores' => ['monitor_series', ['sonarr_series_id' => 42, 'series_id' => 999, 'monitored' => false], 'series'],
]);

test('a monitor proposal for the triggering series is queued', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'requires_approval' => true, 'is_enabled' => true]);
    bindDecisionContextForEvent(ServiceConnection::factory()->sonarr()->create(), ['series' => ['id' => 42]]);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'monitor_series',
        'target_service' => 'sonarr',
        'rationale' => 'Series became unmonitored.',
        'payload' => ['series_id' => 42, 'monitored' => true],
    ])), true);

    expect($result['queued'])->toBeTrue()
        ->and(ActionRequest::sole()->payload['series_id'])->toBe(42);
});

test('delete proposals are refused when the triggering event names no subject', function (string $type, array $payload): void {
    ActionTypeConfig::factory()->create(['type' => $type, 'requires_approval' => true, 'is_enabled' => true]);
    bindDecisionContextForEvent(ServiceConnection::factory()->radarr()->create(), ['eventType' => 'Health']);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => $type,
        'target_service' => 'radarr',
        'rationale' => 'Delete it.',
        'payload' => $payload,
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_not_verifiable')
        ->and(ActionRequest::count())->toBe(0);
})->with([
    'delete_movie' => ['delete_movie', ['radarr_movie_id' => 9]],
    'delete_series' => ['delete_series', ['sonarr_series_id' => 9]],
]);

test('non-destructive series proposals are allowed when the triggering event names no subject', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'set_series_quality_profile', 'requires_approval' => true, 'is_enabled' => true]);
    bindDecisionContextForEvent(ServiceConnection::factory()->sonarr()->create(), ['eventType' => 'Health']);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'set_series_quality_profile',
        'target_service' => 'sonarr',
        'rationale' => 'Upgrade the profile.',
        'payload' => ['series_id' => 7, 'quality_profile_id' => 4],
    ])), true);

    expect($result['queued'])->toBeTrue();
});

test('a model-supplied service_connection_id cannot redirect the proposal to another instance', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'requires_approval' => true, 'is_enabled' => true]);
    $other = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-other.local:8989', 'name' => 'Other Sonarr']);
    $origin = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'name' => 'Sonarr']);
    IndexedSeries::factory()->for($other, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Wrong Show', 'year' => 2001]);
    IndexedSeries::factory()->for($origin, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance', 'year' => 2022]);
    bindDecisionContextForEvent($origin, ['series' => ['id' => 142]]);

    (new ProposeActionTool)->handle(new Request([
        'type' => 'monitor_series',
        'target_service' => 'sonarr',
        'rationale' => 'Monitor it.',
        'payload' => ['series_id' => 142, 'monitored' => true, 'service_connection_id' => $other->id],
    ]));

    $actionRequest = ActionRequest::sole();
    expect($actionRequest->payload['service_connection_id'])->toBe($origin->id)
        ->and($actionRequest->title)->toContain('Severance');
});

/**
 * Binds a context the way RunDecisionAgent builds it after webhook capture
 * trimmed the event row: no webhook event id, only the job's snapshot.
 *
 * @param  array<string, mixed>  $payload
 */
function bindTrimmedDecisionContext(ServiceConnection $serviceConnection, array $payload): DecisionRunContext
{
    $context = new DecisionRunContext(
        webhookEventId: null,
        maxActions: 3,
        sourceService: 'sonarr',
        eventPayload: $payload,
        originConnectionId: $serviceConnection->id,
    );
    app()->instance(DecisionRunContext::class, $context);

    return $context;
}

test('a trimmed event snapshot still binds series proposals to the event subject', function (string $type, array $payload): void {
    ActionTypeConfig::factory()->create(['type' => $type, 'requires_approval' => true, 'is_enabled' => true]);
    bindTrimmedDecisionContext(ServiceConnection::factory()->sonarr()->create(), ['series' => ['id' => 42]]);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => $type,
        'target_service' => 'sonarr',
        'rationale' => 'Act on a different title.',
        'payload' => $payload,
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_mismatch')
        ->and(ActionRequest::count())->toBe(0);
})->with([
    'monitor_series' => ['monitor_series', ['series_id' => 999, 'monitored' => false]],
    'set_series_quality_profile' => ['set_series_quality_profile', ['series_id' => 999, 'quality_profile_id' => 4]],
    'delete_series' => ['delete_series', ['sonarr_series_id' => 999]],
]);

test('a trimmed event snapshot still binds seerr mutations to the triggering request', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'decline_seerr_request', 'requires_approval' => true, 'is_enabled' => true]);
    bindTrimmedDecisionContext(ServiceConnection::factory()->seerr()->create(), ['request' => ['request_id' => 42]]);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'decline_seerr_request',
        'target_service' => 'seerr',
        'rationale' => 'Decline the other request.',
        'payload' => ['seerr_request_id' => 999],
    ])), true);

    expect($result['queued'])->toBeFalse()
        ->and($result['reason'])->toBe('subject_mismatch')
        ->and(ActionRequest::count())->toBe(0);
});

test('a trimmed event snapshot still pins the proposal to the originating connection', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'requires_approval' => true, 'is_enabled' => true]);
    $other = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr-other.local:8989', 'name' => 'Other Sonarr']);
    $origin = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'name' => 'Sonarr']);
    IndexedSeries::factory()->for($other, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Wrong Show', 'year' => 2001]);
    IndexedSeries::factory()->for($origin, 'serviceConnection')->create(['sonarr_id' => 142, 'title' => 'Severance', 'year' => 2022]);
    bindTrimmedDecisionContext($origin, ['series' => ['id' => 142]]);

    $result = json_decode((new ProposeActionTool)->handle(new Request([
        'type' => 'monitor_series',
        'target_service' => 'sonarr',
        'rationale' => 'Monitor it.',
        'payload' => ['series_id' => 142, 'monitored' => true, 'service_connection_id' => $other->id],
    ])), true);

    $actionRequest = ActionRequest::sole();
    expect($result['queued'])->toBeTrue()
        ->and($actionRequest->webhook_event_id)->toBeNull()
        ->and($actionRequest->payload['service_connection_id'])->toBe($origin->id)
        ->and($actionRequest->title)->toContain('Severance');
});
