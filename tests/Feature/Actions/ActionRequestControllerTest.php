<?php

declare(strict_types=1);

use App\Ai\Classification\ClassificationOutcomeRecorder;
use App\Ai\Decision\SecondOpinionVerifier;
use App\Enums\ActionRequestStatus;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ClassificationOutcome;
use App\Models\MediaReplacementAttempt;
use App\Models\User;
use App\Services\Actions\ActionRequestBrowserPayload;
use App\Services\Actions\ActionRequestReviewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

// ---------- INDEX ----------
test('guests are redirected to login', function (): void {
    $this->get(route('actions.requests.index'))->assertRedirect(route('login'));
});

test('viewers cannot access action requests index', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('actions.requests.index'))->assertForbidden();
});

test('members can list action requests', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->count(3)->create();

    $this->actingAs($member)
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Actions/Index')
            ->has('requests.data', 3)
        );
});

test('members see sanitized action results in request listings', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Failed,
        'result' => [
            'success' => false,
            'reason' => 'execution_failed',
            'message' => 'LEAKED-PROVIDER-SECRET',
            'exception' => RuntimeException::class,
        ],
    ]);

    $this->actingAs($member)
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.result', [
                'success' => false,
                'reason' => 'execution_failed',
                'rejection_reason' => null,
            ])
        );
});

test('members see the reviewer rejection reason on a rejected request', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Rejected,
        'result' => ['rejection_reason' => 'Already on disk in 4K.'],
    ]);

    $this->actingAs($member)
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.result', [
                'success' => null,
                'reason' => null,
                'rejection_reason' => 'Already on disk in 4K.',
            ])
        );
});

test('a non-string rejection reason never reaches a member', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Rejected,
        'result' => ['rejection_reason' => ['nested' => 'LEAKED']],
    ]);

    $this->actingAs($member)
        ->get(route('actions.requests.index'))
        ->assertInertia(fn ($page) => $page->where('requests.data.0.result.rejection_reason', null));
});

test('admins receive the full failure result, including its message and outcome flag', function (): void {
    $actionRequest = ActionRequest::factory()->needsReconciliation()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.result.reason', 'needs_reconciliation')
            ->where('requests.data.0.result.indeterminate', true)
            ->where('requests.data.0.result.message', $actionRequest->result['message'])
        );
});

test('members never receive the failure message or outcome flag of an uncertain action', function (): void {
    ActionRequest::factory()->needsReconciliation()->create();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.result', [
                'success' => false,
                'reason' => 'needs_reconciliation',
                'rejection_reason' => null,
            ])
        );
});

test('replacement request listings expose only bounded review fields', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'payload' => [
            'title' => str_repeat('T', 400),
            'detail' => str_repeat('D', 1_100),
            'service' => 'sonarr',
            'service_connection_id' => 37,
            'scope' => 'anime',
            'target' => [
                'episode_file_ids' => [501, 502],
                'private_path' => '/anime/private/Frieren.mkv',
            ],
            'candidate_fingerprint' => 'private-candidate-fingerprint',
            'candidate' => [
                'season_pack' => true,
                'download_url' => 'https://indexer.test/private-download',
            ],
            'required_languages' => ['eng'],
            'confidence' => 97,
            'matched_rules' => ['Trusted English'],
            'selection_mode' => 'automatic',
            'agent_rationale' => str_repeat('R', 1_100),
            'original_history_id' => 999,
            'subtitle_case_id' => 42,
        ],
    ]);

    $response = $this->actingAs($member)
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.payload', [
                'title' => str_repeat('T', 300),
                'detail' => str_repeat('D', 1_000),
                'service' => 'sonarr',
                'scope' => 'anime',
                'required_languages' => ['eng'],
                'confidence' => 97,
                'matched_rules' => ['Trusted English'],
                'selection_mode' => 'automatic',
                'agent_rationale' => str_repeat('R', 1_000),
                'original_history_id' => 999,
                'subtitle_case_id' => 42,
                'affected_file_count' => 2,
                'season_pack' => true,
            ])
        );

    $serializedProps = json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR);

    expect($serializedProps)
        ->not->toContain('/anime/private/Frieren.mkv')
        ->not->toContain('private-candidate-fingerprint')
        ->not->toContain('https://indexer.test/private-download');
});

test('grab release listings never expose the release guid', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->create([
        'type' => 'grab_release',
        'payload' => [
            'service' => 'sonarr',
            'series_id' => 7,
            'guid' => 'https://tracker.test/download?passkey=secret-passkey',
            'indexer_id' => 3,
            'release' => ['title' => 'Show.S01E01.1080p', 'guid' => 'https://tracker.test/download?passkey=secret-passkey'],
            'service_connection_id' => 12,
        ],
    ]);

    $response = $this->actingAs($member)
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.payload.release.title', 'Show.S01E01.1080p')
            ->where('requests.data.0.payload.indexer_id', 3)
            ->missing('requests.data.0.payload.guid')
            ->missing('requests.data.0.payload.release.guid')
        );

    expect(json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR))->not->toContain('secret-passkey');
});

test('index exposes per-status counts independent of the active filter', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    ActionRequest::factory()->count(3)->completed()->create();
    ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);

    // The page is filtered to "pending" but the strip should still know
    // there are completed and executing items in the database.
    $this->actingAs($member)
        ->get(route('actions.requests.index', ['status' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('statusCounts.pending', 2)
            ->where('statusCounts.completed', 3)
            ->where('statusCounts.executing', 1)
        );
});

test('status filter narrows results', function (): void {
    $member = User::factory()->member()->create();
    ActionRequest::factory()->count(2)->create(['status' => ActionRequestStatus::Pending]);
    ActionRequest::factory()->count(3)->completed()->create();

    $this->actingAs($member)
        ->get(route('actions.requests.index', ['status' => 'pending']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('requests.data', 2)
            ->where('filters.status', 'pending')
        );
});

test('replacement rows expose their attempt summary and other rows expose null', function (): void {
    // Explicit timestamps: `latest()` ties on identical created_at are
    // otherwise unordered, which made data.0/data.1 flaky.
    $attempt = MediaReplacementAttempt::factory()->failed()->for(
        ActionRequest::factory()->state(['created_at' => now()->subMinute()]),
        'actionRequest'
    )->create();
    $plain = ActionRequest::factory()->create();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.id', $plain->id)
            ->where('requests.data.0.replacement_attempt', null)
            ->where('requests.data.1.id', $attempt->action_request_id)
            ->where('requests.data.1.replacement_attempt.id', $attempt->id)
            ->where('requests.data.1.replacement_attempt.status', 'failed')
            ->where('requests.data.1.replacement_attempt.failure_reason', 'grab_rejected')
        );
});

test('the request query parameter preselects a row', function (): void {
    $actionRequest = ActionRequest::factory()->create();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index', ['request' => $actionRequest->id]))
        ->assertInertia(fn ($page) => $page->where('preselect', $actionRequest->id));

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index', ['request' => 'abc']))
        ->assertInertia(fn ($page) => $page->where('preselect', null));
});

test('a deep link to a request past the first page sends it as its own prop', function (): void {
    $oldest = ActionRequest::factory()->create(['title' => 'Example oldest request', 'created_at' => now()->subDay()]);
    ActionRequest::factory()->count(29)->create();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index', ['request' => $oldest->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('preselect', $oldest->id)
            ->has('requests.data', 25)
            ->where('preselectedRequest.id', $oldest->id)
            ->where('preselectedRequest.title', 'Example oldest request')
            ->where('preselectedRequest.status', 'pending')
            ->where('requests.links', fn ($links): bool => collect($links)->every(fn (array $link): bool => ! str_contains((string) $link['url'], 'request=')))
        );
});

test('a deep link to a listed or missing request sends no separate prop', function (): void {
    $listed = ActionRequest::factory()->create();
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('actions.requests.index', ['request' => $listed->id]))
        ->assertInertia(fn ($page) => $page->where('preselect', $listed->id)->where('preselectedRequest', null));

    $this->actingAs($member)
        ->get(route('actions.requests.index', ['request' => $listed->id + 100]))
        ->assertInertia(fn ($page) => $page->where('preselectedRequest', null));

    $this->actingAs($member)
        ->get(route('actions.requests.index'))
        ->assertInertia(fn ($page) => $page->where('preselectedRequest', null));
});

test('a partial reload that does not name the deep-linked request does not read it', function (): void {
    $oldest = ActionRequest::factory()->create(['created_at' => now()->subDay()]);
    ActionRequest::factory()->count(25)->create();
    $member = User::factory()->member()->create();
    $version = $this->actingAs($member)->get(route('actions.requests.index'))->viewData('page')['version'];

    DB::enableQueryLog();
    $this->actingAs($member)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Inertia-Partial-Component' => 'Actions/Index', 'X-Inertia-Partial-Data' => 'statusCounts'])
        ->get(route('actions.requests.index', ['request' => $oldest->id]))
        ->assertOk()
        ->assertJsonMissingPath('props.preselectedRequest');

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/where [`"]action_requests[`"]\.[`"]id[`"] = \?/', $sql) === 1)->all())->toBe([]);
    DB::disableQueryLog();

    $this->actingAs($member)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Inertia-Partial-Component' => 'Actions/Index', 'X-Inertia-Partial-Data' => 'preselectedRequest'])
        ->get(route('actions.requests.index', ['request' => $oldest->id]))
        ->assertOk()
        ->assertJsonPath('props.preselectedRequest.id', $oldest->id);
});

test('viewers cannot reach a deep-linked request', function (): void {
    $actionRequest = ActionRequest::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('actions.requests.index', ['request' => $actionRequest->id]))
        ->assertForbidden();
});

// ---------- APPROVE ----------
test('members can approve a pending request', function (): void {
    Queue::fake();
    $member = User::factory()->member()->create();
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs($member)
        ->from(route('actions.requests.index'))
        ->post(route('actions.requests.approve', $request))
        ->assertRedirect(route('actions.requests.index'));

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Approved);
    expect($request->fresh()->approved_by)->toBe($member->id);

    Queue::assertPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $executeActionRequest): bool => $executeActionRequest->actionRequest->id === $request->id);
});

test('approve fails for non-pending status', function (): void {
    Queue::fake();
    $member = User::factory()->member()->create();
    $request = ActionRequest::factory()->completed()->create();

    $this->actingAs($member)
        ->from(route('actions.requests.index'))
        ->post(route('actions.requests.approve', $request))
        ->assertRedirect(route('actions.requests.index'));

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Completed);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('viewers cannot approve', function (): void {
    $viewer = User::factory()->create();
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs($viewer)
        ->post(route('actions.requests.approve', $request))
        ->assertForbidden();
});

// ---------- REJECT ----------
test('members can reject a pending request', function (): void {
    $member = User::factory()->member()->create();
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    $this->actingAs($member)
        ->from(route('actions.requests.index'))
        ->post(route('actions.requests.reject', $request))
        ->assertRedirect(route('actions.requests.index'));

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Rejected);
    expect($request->fresh()->approved_by)->toBe($member->id);
});

// ---------- RETRY ----------
test('members cannot retry', function (): void {
    $member = User::factory()->member()->create();
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Failed]);

    $this->actingAs($member)
        ->post(route('actions.requests.retry', $request))
        ->assertForbidden();
});

test('admins can retry a failed request', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Failed,
        'result' => ['success' => false],
    ]);

    $this->actingAs($admin)
        ->from(route('actions.requests.index'))
        ->post(route('actions.requests.retry', $request))
        ->assertRedirect(route('actions.requests.index'));

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Approved);
    expect($request->fresh()->result)->toBeNull();
    Queue::assertPushed(ExecuteActionRequest::class);
});

test('retry fails for non-failed status', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $request = ActionRequest::factory()->completed()->create();

    $this->actingAs($admin)
        ->from(route('actions.requests.index'))
        ->post(route('actions.requests.retry', $request))
        ->assertRedirect(route('actions.requests.index'));

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Completed);
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('the action queue exposes the description, details and agent reasoning', function (): void {
    ActionRequest::factory()->described()->create([
        'payload' => ['sonarr_series_id' => 142, 'agent_rationale' => str_repeat('r', 1200)],
        'description_verified' => false,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Actions/Index')
            ->where('requests.data.0.title', 'Delete series "Severance (2022)"')
            ->where('requests.data.0.details.1', ['label' => 'Delete files', 'value' => 'Yes'])
            ->where('requests.data.0.description_verified', false)
            ->where('requests.data.0.agent_rationale', str_repeat('r', 1000)));
});

test('a legacy action request exposes null description fields', function (): void {
    ActionRequest::factory()->create();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index'))
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.title', null)
            ->where('requests.data.0.details', [])
            ->where('requests.data.0.agent_rationale', null));
});

test('approving a held action resolves the jev second opinion as positive', function (): void {
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: false, engine: 'jev', confidence: 0.3)->create(['status' => ActionRequestStatus::Pending]);
    resolve(ClassificationOutcomeRecorder::class)->record(ClassificationGate::SecondOpinion, SecondOpinionVerifier::outcomeSubjectKey($actionRequest->id), 'second_opinion', 0.3, ClassificationVerdict::Skipped, 0.8);

    resolve(ActionRequestReviewer::class)->approve($actionRequest, User::factory()->admin()->create());

    expect(ClassificationOutcome::query()->sole()->outcome_positive)->toBeTrue();
});

test('rejecting a held action resolves the jev second opinion as negative', function (): void {
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: false, engine: 'jev', confidence: 0.3)->create(['status' => ActionRequestStatus::Pending]);
    resolve(ClassificationOutcomeRecorder::class)->record(ClassificationGate::SecondOpinion, SecondOpinionVerifier::outcomeSubjectKey($actionRequest->id), 'second_opinion', 0.3, ClassificationVerdict::Skipped, 0.8);

    resolve(ActionRequestReviewer::class)->reject($actionRequest, User::factory()->admin()->create());

    expect(ClassificationOutcome::query()->sole()->outcome_positive)->toBeFalse();
});

test('the browser payload carries a bounded second opinion', function (): void {
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: false, reason: str_repeat('x', 900))->create();

    $secondOpinion = resolve(ActionRequestBrowserPayload::class)->description($actionRequest)['second_opinion'];

    expect($secondOpinion)->toMatchArray(['engine' => 'llm', 'mode' => 'hold', 'agrees' => false, 'failed' => false])
        ->and(mb_strlen((string) $secondOpinion['reason']))->toBe(500)
        ->and($secondOpinion)->not->toHaveKeys(['provider', 'model']);
});

test('a request without a second opinion carries none', function (): void {
    expect(resolve(ActionRequestBrowserPayload::class)->description(ActionRequest::factory()->create())['second_opinion'])->toBeNull();
});

test('each listed request carries its status version', function (): void {
    $actionRequest = ActionRequest::factory()->create();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('actions.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requests.data.0.id', $actionRequest->id)
            ->where('requests.data.0.status_version', $actionRequest->status_version));
});
