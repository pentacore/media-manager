<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionVerifierAgent;
use App\Ai\Decision\DecisionPrompt;
use App\Enums\ActionRequestStatus;
use App\Enums\AiUsageKind;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Benchmark\VerifierCaseCapture;
use App\Services\Benchmark\VerifierCaseInput;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * A described delete_series request with an LLM second opinion linked to
 * inv-verifier-1 and its webhook event (none when $withEvent is false).
 *
 * @param  array<string, mixed>  $attributes
 */
function verifierCaptureRequest(array $attributes = [], bool $withEvent = true): ActionRequest
{
    ActionTypeConfig::factory()->create(['type' => 'delete_series', 'label' => 'Delete series']);

    return ActionRequest::factory()->described()->secondOpinion(agrees: true, reason: 'Looks routine.', invocationId: 'inv-verifier-1')->create([
        'origin' => 'agent',
        'type' => 'delete_series',
        'source_service' => 'emby',
        'webhook_event_id' => $withEvent ? WebhookEvent::factory()->create(['event_type' => 'library.deleted', 'payload' => ['Item' => ['Name' => 'Severance']]])->id : null,
        'payload' => ['series_id' => 7, 'agent_rationale' => 'Emby reported the series removed.'],
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function verifierCaptureFields(): array
{
    return [
        'action_type' => 'delete_series',
        'action_label' => 'Delete series',
        'title' => 'Delete series "Severance (2022)"',
        'description' => 'Emby reported "Severance" was removed from the library. Sonarr will delete the series and its files from disk.',
        'details' => [['label' => 'Series', 'value' => 'Severance (2022)'], ['label' => 'Delete files', 'value' => 'Yes']],
        'verified' => true,
        'payload_json' => DecisionPrompt::payloadJson(['series_id' => 7]),
        'rationale' => 'Emby reported the series removed.',
        'source_service' => 'emby',
        'event_type' => 'library.deleted',
        'event_payload_json' => DecisionPrompt::payloadJson(['Item' => ['Name' => 'Severance']]),
    ];
}

function verifierCaptureUsage(?string $prompt = null): AiUsageRecord
{
    return AiUsageRecord::factory()->create([
        'agent_class' => DecisionVerifierAgent::class,
        'kind' => AiUsageKind::Text,
        'invocation_id' => 'inv-verifier-1',
        'prompt_text' => $prompt ?? VerifierCaseInput::prompt(verifierCaptureFields()),
        'prompt_tokens' => 1_200,
        'completion_tokens' => 40,
    ]);
}

test('a verifier usage row linked to its action request captures the structured case file', function (): void {
    $this->actingAs($admin = User::factory()->admin()->create());
    verifierCaptureRequest(['status' => ActionRequestStatus::Completed, 'approved_by' => $admin->id]);
    $aiUsageRecord = verifierCaptureUsage();

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('draft.task', 'decision_verifier')
            ->where('draft.name', 'Decision verifier: Delete series "Severance (2022)"')
            ->where('draft.input.verifier', verifierCaptureFields())
            ->where('draft.input.prompt', VerifierCaseInput::prompt(verifierCaptureFields()))
            ->where('draft.scoring', ['type' => 'verdict', 'expected_agrees' => true])
            ->where('draft.source_invocation_id', 'inv-verifier-1')
            ->where('draftWarnings', []));
});

test('a usage row whose action request is gone keeps the recorded prompt and asks for a verdict', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $aiUsageRecord = verifierCaptureUsage("PROPOSED ACTION\nType: delete_series (Delete series)");

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertInertia(fn ($page) => $page
            ->where('draft.input', ['prompt' => "PROPOSED ACTION\nType: delete_series (Delete series)"])
            ->where('draft.scoring', ['type' => 'verdict', 'expected_agrees' => null])
            ->where('draftWarnings', [VerifierCaseCapture::NO_REQUEST_WARNING]));
});

test('the expected verdict comes only from a person reviewing the action', function (ActionRequestStatus $actionRequestStatus, bool $reviewed, ?array $result, ?bool $expected): void {
    $actionRequest = verifierCaptureRequest([
        'status' => $actionRequestStatus,
        'approved_by' => $reviewed ? User::factory()->admin()->create()->id : null,
        'result' => $result,
    ]);

    ['draft' => $draft, 'warnings' => $warnings] = resolve(VerifierCaseCapture::class)->draft($actionRequest);

    expect($draft['scoring'])->toBe(['type' => 'verdict', 'expected_agrees' => $expected])
        ->and(in_array(VerifierCaseCapture::NO_REVIEW_WARNING, $warnings, true))->toBe($expected === null);
})->with([
    'approved by a person' => [ActionRequestStatus::Approved, true, null, true],
    'approved, then completed' => [ActionRequestStatus::Completed, true, null, true],
    'approved, then failed' => [ActionRequestStatus::Failed, true, null, true],
    'rejected by a person' => [ActionRequestStatus::Rejected, true, ['rejection_reason' => 'Wrong series.'], false],
    'still pending' => [ActionRequestStatus::Pending, false, null, null],
    'ran automatically' => [ActionRequestStatus::Completed, false, null, null],
    'rejected by the second opinion' => [ActionRequestStatus::Rejected, false, ['success' => false, 'reason' => 'second_opinion_rejected', 'rejection_reason' => 'Rejected by second opinion: Wrong series.'], null],
]);

test('a reviewer who was deleted since leaves the verdict to choose', function (): void {
    $reviewer = User::factory()->admin()->create();
    $actionRequest = verifierCaptureRequest(['status' => ActionRequestStatus::Rejected, 'approved_by' => $reviewer->id]);
    $reviewer->delete();

    ['draft' => $draft, 'warnings' => $warnings] = resolve(VerifierCaseCapture::class)->draft($actionRequest->fresh());

    expect($draft['scoring']['expected_agrees'])->toBeNull()
        ->and($warnings)->toContain(VerifierCaseCapture::NO_REVIEW_WARNING);
});

test('a request without its webhook event captures an empty event with a warning', function (): void {
    ['draft' => $draft, 'warnings' => $warnings] = resolve(VerifierCaseCapture::class)->draft(verifierCaptureRequest(withEvent: false));

    expect($draft['input']['verifier'])->toMatchArray(['event_type' => null, 'event_payload_json' => '{}'])
        ->and($warnings)->toContain(VerifierCaseCapture::NO_EVENT_WARNING);
});

test('the draft warns only when the rebuilt case file differs from a complete recorded prompt', function (string $recorded, bool $warns): void {
    verifierCaptureUsage($recorded);

    ['warnings' => $warnings] = resolve(VerifierCaseCapture::class)->draft(verifierCaptureRequest());

    expect(in_array(VerifierCaseCapture::PROMPT_DIFFERS_WARNING, $warnings, true))->toBe($warns);
})->with([
    'identical' => [fn (): string => VerifierCaseInput::prompt(verifierCaptureFields()), false],
    'different' => ['PROPOSED ACTION (older wording)', true],
    'recorded prompt cut short' => ['PROPOSED ACTION…', false],
]);

test('an event payload too large to store keeps the rebuilt case file as a raw prompt', function (): void {
    $actionRequest = verifierCaptureRequest();
    $actionRequest->webhookEvent->update(['payload' => ['blob' => str_repeat('a', 70_000)]]);

    ['draft' => $draft, 'warnings' => $warnings] = resolve(VerifierCaseCapture::class)->draft($actionRequest->fresh());

    expect(array_keys($draft['input']))->toBe(['prompt'])
        ->and($draft['input']['prompt'])->toContain('… (truncated)')
        ->and($warnings)->toContain(VerifierCaseCapture::PAYLOAD_TOO_LARGE_WARNING);
});

test('an action request link opens the form with source tokens from the linked usage row', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $actionRequest = verifierCaptureRequest();
    verifierCaptureUsage();

    $this->get(route('admin.benchmarks.cases.create', ['action_request' => $actionRequest->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Benchmarks/Cases/Form')
            ->where('draft.task', 'decision_verifier')
            ->where('draft.input.verifier.title', 'Delete series "Severance (2022)"')
            ->where('draft.source_invocation_id', 'inv-verifier-1')
            ->where('draft.source_prompt_tokens', 1_200)
            ->where('draft.source_completion_tokens', 40)
            ->where('draftWarnings', [VerifierCaseCapture::NO_REVIEW_WARNING]));
});

test('a request without a usable second opinion cannot be captured', function (array $attributes, string $message): void {
    $this->actingAs(User::factory()->admin()->create());
    $actionRequest = verifierCaptureRequest($attributes);

    $this->from(route('actions.requests.index'))
        ->get(route('admin.benchmarks.cases.create', ['action_request' => $actionRequest->id]))
        ->assertRedirect(route('actions.requests.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', $message);
})->with([
    'no second opinion' => [['second_opinion' => null], VerifierCaseCapture::NO_SECOND_OPINION],
    'a failed check' => [['second_opinion' => ['engine' => 'llm', 'mode' => 'hold', 'agrees' => false, 'failed' => true, 'confidence' => null, 'reason' => 'timeout', 'provider' => null, 'model' => null, 'invocation_id' => null]], VerifierCaseCapture::FAILED_SECOND_OPINION],
    'no description' => [['title' => null, 'description' => null], VerifierCaseCapture::NO_DESCRIPTION],
]);

test('members cannot capture a second opinion', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $this->get(route('admin.benchmarks.cases.create', ['action_request' => verifierCaptureRequest()->id]))->assertForbidden();
});
