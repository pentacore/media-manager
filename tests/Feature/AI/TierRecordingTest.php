<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\FreePoolOverflowBehavior;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');

    $pool = AiFreeUsagePool::factory()->unified(1_000_000)->overflow(FreePoolOverflowBehavior::Split)->create(['name' => 'Luna free']);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'free_usage_pool_id' => $pool->id]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-nano']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::High)->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->reasoning(AiReasoningLevel::Low)->create();
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'prompt_tokens' => 900_000, 'completion_tokens' => 0]);
});

test('a chat turn records which tier answered', function (): void {
    MediaAgent::fake(['Hello there']);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.chat.send'), ['message' => 'Hi']);

    $response->assertOk();
    expect($response->json('answered_by.model'))->toBe('gpt-5-nano')
        ->and($response->json('answered_by.tier'))->toBe(['position' => 2, 'count' => 2, 'reason' => 'Tier 1: Luna free below 20% (10% left)']);

    $usage = AiUsageRecord::query()->where('agent_class', MediaAgent::class)->latest('id')->first();
    expect($usage?->tier_position)->toBe(2);

    $meta = json_decode((string) DB::table('agent_conversation_messages')->where('role', 'assistant')->latest('id')->value('meta'), true);
    expect($meta['tier'] ?? null)->toBe(['position' => 2, 'count' => 2, 'reason' => 'Tier 1: Luna free below 20% (10% left)']);
});

test('the conversation history replays each answer tier', function (): void {
    MediaAgent::fake(['Hello there']);
    $admin = User::factory()->admin()->create();
    $conversationId = $this->actingAs($admin)->postJson(route('ai.chat.send'), ['message' => 'Hi'])->json('conversation_id');

    $this->actingAs($admin)
        ->getJson(route('ai.conversations.show', $conversationId))
        ->assertJsonPath('messages.1.answered_by.tier.position', 2);
});

test('the chat model options carry the live default tier', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('ai.chat.model-options'))
        ->assertJsonPath('defaults.model', 'gpt-5-nano')
        ->assertJsonPath('defaults.tier.position', 2);
});

test('runs without tiers record no tier', function (): void {
    AiTaskModel::query()->delete();
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5-nano')->create();
    MediaAgent::fake(['Hello there']);

    $response = $this->actingAs(User::factory()->admin()->create())->postJson(route('ai.chat.send'), ['message' => 'Hi']);

    expect($response->json('answered_by.tier'))->toBeNull()
        ->and(AiUsageRecord::query()->where('agent_class', MediaAgent::class)->latest('id')->value('tier_position'))->toBeNull();
});

test('a failed run records the tier it fell through to', function (): void {
    MediaAgent::fake(fn (): never => throw new RuntimeException('provider fell over'));

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.chat.send'), ['message' => 'Hi']);

    $usage = AiUsageRecord::query()->where('agent_class', MediaAgent::class)->latest('id')->first();

    expect($usage?->status)->toBe('failed')
        ->and($usage?->tier_position)->toBe(2);
});
