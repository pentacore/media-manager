<?php

declare(strict_types=1);

use App\Enums\AiMode;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\Chat\ChatTurnGuard;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function chatTurnGuardExhaustBudget(): void
{
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'test-model', 'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0]);
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'test-model', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000]);
    resolve(AiSettings::class)->setHardBudgetUsd(1.0);
}

function chatTurnGuardExhaustRateLimit(): void
{
    resolve(AiSettings::class)->setRateLimitsEnforced(true);
    $model = resolve(AiSettings::class)->chatSelection()->model;
    $aiModelPrice = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model]);
    $aiModelPrice->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);
    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'inv-'.uniqid(), 'agent_class' => 'TestAgent', 'provider' => 'openai', 'model' => $model,
        'prompt_tokens' => 10, 'completion_tokens' => 5, 'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0, 'tool_calls_count' => 0, 'status' => 'success', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('a turn with budget and rate limit to spare passes the pre-flight', function (): void {
    expect(resolve(ChatTurnGuard::class)->refuse(['message' => 'hi'], User::factory()->admin()->create()))->toBeNull();
});

test('the requested mode applies before the budget refuses the turn', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Executive);
    chatTurnGuardExhaustBudget();

    $jsonResponse = resolve(ChatTurnGuard::class)->refuse(['message' => 'hi', 'mode' => 'advisory'], User::factory()->admin()->create());

    expect($jsonResponse?->getStatusCode())->toBe(402)
        ->and(resolve(AiSettings::class)->mode())->toBe(AiMode::Advisory);
});

test('the budget refuses before the rate limit, and the rate limit before the conversation check', function (bool $budget, bool $rateLimit, int $status): void {
    if ($budget) {
        chatTurnGuardExhaustBudget();
    }

    if ($rateLimit) {
        chatTurnGuardExhaustRateLimit();
    }

    $jsonResponse = resolve(ChatTurnGuard::class)->refuse(
        ['message' => 'hi', 'conversation_id' => (string) Str::uuid7()],
        User::factory()->admin()->create(),
    );

    expect($jsonResponse?->getStatusCode())->toBe($status);
})->with([
    'all three fail' => [true, true, 402],
    'rate limit and conversation fail' => [false, true, 429],
    'only the conversation fails' => [false, false, 404],
]);

test('a failover provider lets an exhausted primary through the pre-flight', function (): void {
    chatTurnGuardExhaustRateLimit();
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();

    expect(resolve(ChatTurnGuard::class)->refuse(['message' => 'hi'], User::factory()->admin()->create()))->toBeNull();
});

test('only the owner of an unarchived conversation may continue it', function (): void {
    $owner = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'participant_type' => $owner->getMorphClass(), 'participant_id' => $owner->id,
        'title' => 'Example chat', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $chatTurnGuard = resolve(ChatTurnGuard::class);

    expect($chatTurnGuard->conversationIsAvailable($conversationId, $owner))->toBeTrue()
        ->and($chatTurnGuard->conversationIsAvailable($conversationId, User::factory()->admin()->create()))->toBeFalse()
        ->and($chatTurnGuard->conversationIsAvailable($conversationId, null))->toBeFalse();

    DB::table('agent_conversations')->where('id', $conversationId)->update(['archived_at' => now()]);

    expect($chatTurnGuard->conversationIsAvailable($conversationId, $owner))->toBeFalse();
});
