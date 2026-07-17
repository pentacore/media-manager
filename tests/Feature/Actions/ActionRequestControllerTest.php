<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\User;
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
