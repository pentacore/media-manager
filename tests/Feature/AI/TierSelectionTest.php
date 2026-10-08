<?php

declare(strict_types=1);

use App\Ai\ChatTurnContext;
use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\FreePoolOverflowBehavior;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\FreePoolAccounting;
use App\Services\AiUsage\PoolHeadroom;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.default', 'openai');
    config()->set('mediamanager.ai.model', 'gpt-config-chat');
    config()->set('mediamanager.ai.advisor_reasoning_level');
    config()->set('mediamanager.ai.pricing.updater_model', '');
    config()->set('mediamanager.ai.sub_agent_model', '');
    config()->set('mediamanager.decision_agent.model', '');
    config()->set('mediamanager.decision_agent.reasoning_level', '');
});

function tierPool(int $cap = 1_000_000): AiFreeUsagePool
{
    return AiFreeUsagePool::factory()->unified($cap)->overflow(FreePoolOverflowBehavior::Split)->create(['name' => 'Luna free']);
}

function tierPrice(string $model, ?AiFreeUsagePool $aiFreeUsagePool = null): AiModelPrice
{
    return AiModelPrice::factory()->create(['provider' => 'openai', 'model' => $model, 'free_usage_pool_id' => $aiFreeUsagePool?->id]);
}

function tierUsage(string $model, int $tokens): void
{
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => $model, 'prompt_tokens' => $tokens, 'completion_tokens' => 0]);
}

/**
 * Chat tiers: luna while its pool has the given minimums left, then nano.
 */
function tierChatList(?int $percent, ?int $tokens = null, AiTask $aiTask = AiTask::Chat): void
{
    AiTaskModel::factory()->task($aiTask)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::High)->conditions($percent, $tokens)->create();
    AiTaskModel::factory()->task($aiTask)->position(1)->selecting('openai', 'gpt-5-nano')->reasoning(AiReasoningLevel::Low)->create();
}

function tierResolver(): TaskModelResolver
{
    resolve(PoolHeadroom::class)->flush();
    $taskModelResolver = resolve(TaskModelResolver::class);
    $taskModelResolver->flush();

    return $taskModelResolver;
}

test('the first tier runs while its pool has enough left', function (): void {
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 500_000);
    tierChatList(20);

    $resolvedSelection = tierResolver()->resolve(AiTask::Chat);

    expect([$resolvedSelection->model, $resolvedSelection->reasoning])->toBe(['gpt-5.6-luna', AiReasoningLevel::High])
        ->and($resolvedSelection->tier?->toArray())->toBe(['position' => 1, 'count' => 2, 'reason' => null]);
});

test('a tier below its percent minimum falls through with a reason', function (): void {
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 900_000);
    tierChatList(20);

    $resolvedSelection = tierResolver()->resolve(AiTask::Chat);

    expect([$resolvedSelection->model, $resolvedSelection->reasoning])->toBe(['gpt-5-nano', AiReasoningLevel::Low])
        ->and($resolvedSelection->tier?->toArray())->toBe(['position' => 2, 'count' => 2, 'reason' => 'Tier 1: Luna free below 20% (10% left)']);
});

test('a tier below its token minimum falls through even when its percent holds', function (): void {
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 900_000);
    tierChatList(5, 200_000);

    $resolvedSelection = tierResolver()->resolve(AiTask::Chat);

    expect($resolvedSelection->model)->toBe('gpt-5-nano')
        ->and($resolvedSelection->tier?->reason())->toBe('Tier 1: Luna free below 200,000 tokens (100,000 left)');
});

test('conditions on a model without a pool never block it', function (): void {
    tierPrice('gpt-5.6-luna');
    tierPrice('gpt-5-nano');
    tierChatList(50, 1_000);

    expect(tierResolver()->resolve(AiTask::Chat)->model)->toBe('gpt-5.6-luna');
});

test('a rate-limited tier is skipped only while enforcement is on', function (): void {
    $luna = tierPrice('gpt-5.6-luna');
    $luna->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 10);
    tierChatList(null);

    expect(tierResolver()->resolve(AiTask::Chat)->model)->toBe('gpt-5.6-luna');

    resolve(AiSettings::class)->setRateLimitsEnforced(true);
    $resolvedSelection = tierResolver()->resolve(AiTask::Chat);

    expect($resolvedSelection->model)->toBe('gpt-5-nano')
        ->and($resolvedSelection->tier?->reason())->toStartWith('Tier 1: rate-limited (1 of 1');
});

test('the last tier always runs, even when its own pool is drained', function (): void {
    $pool = tierPool();
    tierPrice('gpt-5.6-luna', $pool);
    tierPrice('gpt-5-nano', $pool);
    tierUsage('gpt-5.6-luna', 1_000_000);
    tierChatList(20);

    expect(tierResolver()->resolve(AiTask::Chat)->model)->toBe('gpt-5-nano');
});

test('an inherit tier in an event list hands off to the decision default list', function (): void {
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 900_000);
    AiTaskModel::factory()->task(AiTask::Decision)->selecting('openai', 'gpt-5-nano')->reasoning(AiReasoningLevel::High)->create();
    AiTaskModel::factory()->event('sonarr:Download')->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->event('sonarr:Download')->position(1)->reasoning(AiReasoningLevel::Low)->create();
    AiTaskModel::factory()->event('radarr:Grab')->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->event('radarr:Grab')->position(1)->create();

    $withReasoning = tierResolver()->resolve(AiTask::Decision, 'sonarr:Download');
    $inheritingReasoning = tierResolver()->resolve(AiTask::Decision, 'radarr:Grab');

    expect([$withReasoning->model, $withReasoning->reasoning])->toBe(['gpt-5-nano', AiReasoningLevel::Low])
        ->and($withReasoning->tier?->toArray())->toBe(['position' => 2, 'count' => 2, 'reason' => 'Tier 1: Luna free below 20% (10% left)'])
        ->and([$inheritingReasoning->model, $inheritingReasoning->reasoning])->toBe(['gpt-5-nano', AiReasoningLevel::High]);
});

test('tasks that follow a tiered chat list take its pair and tier but not its reasoning', function (AiTask $aiTask): void {
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 900_000);
    tierChatList(20);

    $resolvedSelection = tierResolver()->resolve($aiTask);

    expect([$resolvedSelection->model, $resolvedSelection->reasoning])->toBe(['gpt-5-nano', AiReasoningLevel::ProviderDefault])
        ->and($resolvedSelection->tier?->position)->toBe(2);
})->with([AiTask::Decision, AiTask::FileInspector, AiTask::StuckDownloadInvestigator, AiTask::PriceUpdater]);

test('a conversation model override bypasses tiers and keeps the first tier reasoning', function (): void {
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 900_000);
    tierChatList(20);
    resolve(ChatTurnContext::class)->apply('anthropic', 'claude-opus-5-5', null);

    $resolvedSelection = tierResolver()->resolve(AiTask::Chat);

    expect([$resolvedSelection->model, $resolvedSelection->reasoning, $resolvedSelection->tier])
        ->toBe(['claude-opus-5-5', AiReasoningLevel::High, null]);
});

test('a conversation reasoning override wins over the tier reasoning', function (): void {
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 900_000);
    tierChatList(20);
    resolve(ChatTurnContext::class)->apply(null, null, AiReasoningLevel::None);

    $resolvedSelection = tierResolver()->resolve(AiTask::Chat);

    expect([$resolvedSelection->model, $resolvedSelection->reasoning])->toBe(['gpt-5-nano', AiReasoningLevel::None]);
});

test('when pool status fails every conditional tier is skipped', function (): void {
    Exceptions::fake();
    tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierChatList(20);
    $this->mock(FreePoolAccounting::class, fn ($mock) => $mock->shouldReceive('status')->andThrow(new RuntimeException('accounting down')));
    app()->forgetScopedInstances();

    $resolvedSelection = tierResolver()->resolve(AiTask::Chat);

    expect($resolvedSelection->model)->toBe('gpt-5-nano')
        ->and($resolvedSelection->tier?->reason())->toBe('Tier 1: free pool status unavailable');
    Exceptions::assertReported(RuntimeException::class);
});

test('the list walk is memoised until flushed', function (): void {
    tierPrice('gpt-5.6-luna');
    tierPrice('gpt-5-nano');
    tierChatList(null);
    $taskModelResolver = tierResolver();

    expect($taskModelResolver->resolve(AiTask::Chat)->model)->toBe('gpt-5.6-luna');

    DB::table('ai_task_models')->where('position', 0)->delete();

    expect($taskModelResolver->resolve(AiTask::Chat)->model)->toBe('gpt-5.6-luna');

    $taskModelResolver->flush();

    expect($taskModelResolver->resolve(AiTask::Chat)->model)->toBe('gpt-5-nano');
});

test('a single-tier list reports no tier', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    expect(tierResolver()->resolve(AiTask::Chat)->tier)->toBeNull();
});

test('a tier whose pool link was removed stays eligible', function (): void {
    $luna = tierPrice('gpt-5.6-luna', tierPool());
    tierPrice('gpt-5-nano');
    tierUsage('gpt-5.6-luna', 1_000_000);
    tierChatList(20);
    $luna->update(['free_usage_pool_id' => null]);

    expect(tierResolver()->resolve(AiTask::Chat)->model)->toBe('gpt-5.6-luna');
});
