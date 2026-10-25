<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Decision\DecisionPrompt;
use App\Enums\AiUsageKind;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\AgentDecision;
use App\Models\AiUsageRecord;
use App\Models\ClassificationOutcome;
use App\Models\WebhookEvent;
use App\Services\Benchmark\CaseDraftFromUsage;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * A recorded DecisionAgent run about one sonarr event, with its decision row;
 * the webhook row is gone when $trimmed.
 *
 * @param  array<string, mixed>  $payload
 * @return array{usage: AiUsageRecord, decision: AgentDecision}
 */
function captureDecisionRun(array $payload, string $eventType = 'Grab', bool $trimmed = false): array
{
    $usage = AiUsageRecord::factory()->create([
        'agent_class' => DecisionAgent::class,
        'kind' => AiUsageKind::Text,
        'prompt_text' => DecisionPrompt::build('sonarr', $eventType, $payload),
    ]);
    $decision = AgentDecision::factory()->create([
        'webhook_event_id' => $trimmed ? null : WebhookEvent::factory()->create(['event_type' => $eventType, 'payload' => $payload])->id,
        'service' => 'sonarr',
        'event_type' => $eventType,
        'invocation_id' => $usage->invocation_id,
    ]);

    return ['usage' => $usage, 'decision' => $decision];
}

function captureActionKindOutcome(AgentDecision $decision, ClassificationVerdict $verdict, string $predicted): void
{
    ClassificationOutcome::factory()->create([
        'gate' => ClassificationGate::ActionKind,
        'subject_key' => ClassificationOutcome::subjectKey('decision', (int) $decision->webhook_event_id),
        'question' => 'action_kind',
        'verdict' => $verdict,
        'predicted' => $predicted,
    ]);
}

test('a webhook decision captures the event, the scope and the production prompt', function (): void {
    $payload = ['zeta' => 1, 'series' => ['id' => 7], 'alpha' => 2];
    ['usage' => $usage, 'decision' => $decision] = captureDecisionRun($payload);
    captureActionKindOutcome($decision, ClassificationVerdict::Scoped, 'library_change');

    ['draft' => $draft, 'warnings' => $warnings] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect($draft['input'])->toEqual([
        'prompt' => $usage->prompt_text,
        'event' => ['service' => 'sonarr', 'event_type' => 'Grab', 'payload_json' => DecisionPrompt::payloadJson($payload)],
        'action_kind' => 'library_change',
        'event_key' => 'sonarr:Grab',
    ])
        ->and($draft['input']['prompt'])->toBe($usage->prompt_text)
        ->and($draft['name'])->toBe('Decision agent: sonarr Grab')
        ->and($draft['source_truncated'])->toBeFalse()
        ->and($warnings)->toBe([]);
});

test('an unscoped classification captures the full toolset without a warning', function (): void {
    ['usage' => $usage, 'decision' => $decision] = captureDecisionRun(['series' => ['id' => 7]]);
    captureActionKindOutcome($decision, ClassificationVerdict::Unscoped, 'other');

    ['draft' => $draft, 'warnings' => $warnings] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect($draft['input']['action_kind'])->toBeNull()->and($warnings)->toBe([]);
});

test('a stuck import captures the full toolset without a warning', function (): void {
    ['usage' => $usage] = captureDecisionRun(['downloadId' => 'dl-1'], 'ManualInteractionRequired');

    ['draft' => $draft, 'warnings' => $warnings] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect($draft['input']['action_kind'])->toBeNull()->and($warnings)->toBe([]);
});

test('a run whose scope was not recorded warns and keeps the full toolset', function (): void {
    ['usage' => $usage] = captureDecisionRun(['series' => ['id' => 7]]);

    ['draft' => $draft, 'warnings' => $warnings] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect($draft['input']['action_kind'])->toBeNull()
        ->and($draft['source_truncated'])->toBeFalse()
        ->and($warnings)->toBe(['The gate scope of this run was not recorded; the case runs with the full toolset.']);
});

test('a trimmed webhook event is rebuilt from the recorded prompt', function (): void {
    $payload = ['zeta' => 1, 'series' => ['id' => 7, 'title' => 'Dune']];
    ['usage' => $usage] = captureDecisionRun($payload, trimmed: true);

    ['draft' => $draft, 'warnings' => $warnings] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect(DecisionPrompt::payloadFromJson($draft['input']['event']['payload_json']))->toBe($payload)
        ->and($draft['input']['prompt'])->toBe($usage->prompt_text)
        ->and($warnings)->toBe(['The gate scope of this run was not recorded; the case runs with the full toolset.']);
});

test('a payload cut at 8000 characters keeps the recorded prompt as free text and warns', function (): void {
    ['usage' => $usage] = captureDecisionRun(['blob' => str_repeat('a', 9000)], trimmed: true);

    ['draft' => $draft, 'warnings' => $warnings] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect($draft['input'])->toEqual(['prompt' => $usage->prompt_text, 'event_key' => 'sonarr:Grab'])
        ->and($draft['source_truncated'])->toBeTrue()
        ->and($warnings)->toBe(['The event payload could not be recovered in full, so the case keeps the recorded prompt as free text.']);
});

test('an empty production payload captures as an empty object and rebuilds the same prompt', function (): void {
    ['usage' => $usage] = captureDecisionRun([], 'Health');

    ['draft' => $draft] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect($draft['input']['event']['payload_json'])->toBe('{}')
        ->and($draft['input']['prompt'])->toBe($usage->prompt_text)
        ->and($draft['input']['prompt'])->toContain("\n[]\n");
});

test('a decision usage row with a hand-written prompt and no decision row stays free text', function (): void {
    $usage = AiUsageRecord::factory()->create(['agent_class' => DecisionAgent::class, 'kind' => AiUsageKind::Text, 'prompt_text' => 'Import failed']);

    ['draft' => $draft, 'warnings' => $warnings] = resolve(CaseDraftFromUsage::class)->draft($usage);

    expect($draft['input'])->toBe(['prompt' => 'Import failed'])->and($warnings)->toBe([]);
});
