<?php

declare(strict_types=1);

use App\Ai\TaskModelResolver;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\FreePoolOverflowBehavior;
use App\Models\ActivityLog;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use App\Models\User;
use App\Services\AiUsage\FreePoolAccounting;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.anthropic.key', 'sk-ant-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-nano']);
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']);
});

/**
 * Single selections per task (as before) are sent as one-tier lists; pass
 * `['tiers' => [...]]` for a task or event override to send several.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aiModelsPayload(array $overrides = []): array
{
    $blank = ['provider' => null, 'model' => null, 'reasoning' => null];

    $payload = array_replace_recursive([
        'tasks' => [
            'chat' => ['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'reasoning' => 'medium'],
            'title' => $blank,
            'decision' => $blank,
            'decision_verifier' => $blank,
            'file_inspector' => $blank,
            'stuck_download_investigator' => $blank,
            'price_updater' => $blank,
            'benchmark_judge' => $blank,
        ],
        'failover' => ['provider' => null, 'model' => null],
        'event_overrides' => [],
    ], $overrides);

    $asTiers = static fn (mixed $selection): mixed => match (true) {
        ! is_array($selection) => $selection,
        array_key_exists('tiers', $selection) => ['tiers' => $selection['tiers']],
        default => ['tiers' => [[...$selection, 'min_pool_percent' => null, 'min_pool_tokens' => null]]],
    };

    $payload['tasks'] = array_map($asTiers, $payload['tasks']);
    $payload['event_overrides'] = array_map(
        static fn (mixed $override): mixed => is_array($override)
            ? ['event_key' => $override['event_key'] ?? null, ...$asTiers(array_diff_key($override, ['event_key' => true]))]
            : $override,
        $payload['event_overrides'],
    );

    return $payload;
}

test('non-admins cannot open the ai models page', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('admin.ai-models.index'))
        ->assertForbidden();
});

test('the index shows each task with its resolved selection', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::Medium)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiModels/Index')
            ->where('tasks.0.task', 'chat')
            ->where('tasks.0.tiers.0.model', 'gpt-5.6-luna')
            ->where('tasks.0.resolved.reasoning', 'medium')
            ->where('tasks.2.task', 'decision')
            ->where('tasks.2.tiers.0.model', null)
            ->where('tasks.2.resolved.model', 'gpt-5.6-luna')
            ->has('models.openai')
            ->has('reasoningLevels')
            ->has('tasks', 8));
});

test('the index exposes what an inherit tier runs on beside the live resolution', function (): void {
    config()->set('mediamanager.ai.model', 'gpt-config-chat');
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tasks.0.resolved.model', 'gpt-5.6-luna')
            ->where('tasks.0.inherited.model', 'gpt-config-chat')
            ->where('tasks.0.inherited.provider', 'openai'));
});

test('the index summary names the list a following task takes its tier from', function (): void {
    $pool = AiFreeUsagePool::factory()->unified(1_000_000)->overflow(FreePoolOverflowBehavior::Split)->create(['name' => 'Luna free']);
    AiModelPrice::query()->where('model', 'gpt-5.6-luna')->update(['free_usage_pool_id' => $pool->id]);
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'prompt_tokens' => 900_000, 'completion_tokens' => 0]);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.0.resolved.tier.via', null)
            ->where('tasks.2.resolved.tier.via', 'Chat')
            ->where('tasks.2.resolved.tier.reason', 'Chat tier 1: Luna free below 20% (10% left)'));
});

test('the index hints when the resolved model does not reason', function (): void {
    AiModelPrice::query()->where('model', 'gpt-5-nano')->update(['supports_reasoning' => false]);
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-5-nano')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page->where('tasks.1.resolved.reasoning_hint', "Model doesn't reason"));
});

test('saving writes task rows and drops all-inherit rows', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-5-nano')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['file_inspector' => ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => 'low']],
        ]))
        ->assertRedirect(route('admin.ai-models.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'AI models updated.');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->first()?->reasoning)->toBe(AiReasoningLevel::Medium)
        ->and(AiTaskModel::query()->forTask(AiTask::FileInspector)->first()?->model)->toBe('gpt-5-nano')
        ->and(AiTaskModel::query()->forTask(AiTask::Title)->exists())->toBeFalse();
});

test('a model must be priced under the chosen provider', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['chat' => ['provider' => 'anthropic', 'model' => 'gpt-5.6-luna']],
        ]))
        ->assertSessionHasErrors('tasks.chat.tiers.0.model');

    expect(AiTaskModel::query()->count())->toBe(0);
});

test('a model without a provider is rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['price_updater' => ['provider' => null, 'model' => 'gpt-5-nano']],
        ]))
        ->assertSessionHasErrors('tasks.price_updater.tiers.0.provider');
});

test('title accepts the auto sentinel without a price row', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['title' => ['provider' => 'openai', 'model' => 'auto']],
        ]))
        ->assertSessionHasNoErrors();
});

test('event overrides are saved, replaced and removed', function (): void {
    AiTaskModel::factory()->event('radarr:Grab')->reasoning(AiReasoningLevel::Low)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'event_overrides' => [
                ['event_key' => 'sonarr:Download', 'provider' => null, 'model' => null, 'reasoning' => 'none'],
            ],
        ]))
        ->assertSessionHasNoErrors();

    expect(AiTaskModel::query()->where('scope', 'sonarr:Download')->first()?->reasoning)->toBe(AiReasoningLevel::None)
        ->and(AiTaskModel::query()->where('scope', 'radarr:Grab')->exists())->toBeFalse();
});

test('an override for an event that is not allowlisted survives saves and is flagged', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    AiTaskModel::factory()->event('radarr:Grab')->reasoning(AiReasoningLevel::Low)->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('eventOverrides.0.event_key', 'radarr:Grab')
            ->where('eventOverrides.0.enabled', false));

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'event_overrides' => [['event_key' => 'radarr:Grab', 'provider' => null, 'model' => null, 'reasoning' => 'low']],
        ]))
        ->assertSessionHasNoErrors();

    // Saving the decision agent page must not touch overrides.
    $this->actingAs($admin)->put(route('admin.decision-agent.update'), [
        'enabled' => true, 'event_allowlist' => [], 'allow_manual_import' => false,
        'notify_on_suggest' => false, 'notify_on_act' => false, 'max_actions_per_run' => 3,
        'second_opinion_engine' => 'llm', 'second_opinion_jev_threshold' => 0.8,
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(AiTaskModel::query()->where('scope', 'radarr:Grab')->exists())->toBeTrue();
});

test('event override keys must be known and distinct', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'event_overrides' => [
                ['event_key' => 'sonarr:NotAnEvent', 'provider' => null, 'model' => null, 'reasoning' => 'low'],
                ['event_key' => 'sonarr:Download', 'provider' => null, 'model' => null, 'reasoning' => 'low'],
                ['event_key' => 'sonarr:Download', 'provider' => null, 'model' => null, 'reasoning' => 'high'],
            ],
        ]))
        ->assertSessionHasErrors(['event_overrides.0.event_key', 'event_overrides.1.event_key']);
});

test('turning failover off removes its row; a failover model must be priced', function (): void {
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'anthropic'])->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['failover' => ['provider' => 'anthropic', 'model' => 'gpt-5-nano']]))
        ->assertSessionHasErrors('failover.model');

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload())
        ->assertSessionHasNoErrors();

    expect(AiTaskModel::query()->forTask(AiTask::Failover)->exists())->toBeFalse();
});

test('saving records one audit row for the ai models group', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload());

    expect(ActivityLog::query()->where('action', 'settings.updated')->where('subject_type', 'ai_models')->count())->toBe(1);
});

test('malformed selections get a validation error instead of a server error', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['chat' => ['provider' => 'openai', 'model' => ['x']]],
        ]))
        ->assertSessionHasErrors('tasks.chat.tiers.0.model');

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['event_overrides' => ['not-an-array']]))
        ->assertSessionHasErrors('event_overrides.0.event_key');
});

/**
 * The provider + model a task resolves to after a save.
 *
 * @return array{0: string, 1: string}
 */
function aiModelsResolvedPair(AiTask $aiTask): array
{
    $modelSelection = resolve(TaskModelResolver::class)->resolve($aiTask)->modelSelection();

    return [$modelSelection->provider, $modelSelection->model];
}

test('the index exposes each task saved provider, the resolved default provider and the failover', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'anthropic/claude-sonnet-5')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.0.tiers.0.provider', 'openrouter')
            ->where('tasks.0.tiers.0.model', 'anthropic/claude-sonnet-5')
            ->where('tasks.1.tiers.0.provider', null)
            ->where('tasks.1.resolved.provider', 'openai')
            ->where('tasks.4.tiers.0.provider', null)
            ->where('tasks.4.resolved.provider', 'openrouter')
            ->where('failover', ['provider' => null, 'model' => null])
            ->has('models.openrouter')
            ->where('providers', fn ($providers): bool => collect($providers)->contains('openrouter')));
});

test('the title auto sentinel round-trips while the resolved model is concrete', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', AiSettings::AUTO_MODEL)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.1.task', 'title')
            ->where('tasks.1.tiers.0.model', AiSettings::AUTO_MODEL)
            ->where('tasks.1.allow_auto', true)
            ->where('tasks.1.resolved.model', fn (string $model): bool => $model !== AiSettings::AUTO_MODEL && $model !== ''));
});

test('a migrated auto title row on the default provider round-trips through the index and a save', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting((string) config('ai.default'), AiSettings::AUTO_MODEL)->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.1.task', 'title')
            ->where('tasks.1.tiers.0.provider', 'openai')
            ->where('tasks.1.tiers.0.model', AiSettings::AUTO_MODEL));

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['title' => ['provider' => 'openai', 'model' => AiSettings::AUTO_MODEL]],
        ]))
        ->assertSessionHasNoErrors();

    expect(AiTaskModel::query()->forTask(AiTask::Title)->first())
        ->provider->toBe('openai')
        ->model->toBe(AiSettings::AUTO_MODEL);
});

test('a legacy row with a model and no provider shows the default provider', function (): void {
    AiTaskModel::factory()->task(AiTask::FileInspector)->state(['model' => 'gpt-5-nano'])->create();
    AiTaskModel::factory()->event('radarr:Grab')->state(['model' => 'gpt-5-nano'])->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.4.task', 'file_inspector')
            ->where('tasks.4.tiers.0.provider', 'openai')
            ->where('tasks.4.tiers.0.model', 'gpt-5-nano')
            ->where('tasks.4.resolved.provider', 'openai')
            ->where('tasks.5.tiers.0.provider', null)
            ->where('eventOverrides.0.tiers.0.provider', 'openai'));
});

test('the index lists selected models the hard cap cannot price', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(10.0);
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openai', 'mystery-model')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('unpricedModels', fn ($models): bool => collect($models)->contains(fn (array $model): bool => $model['role'] === 'File inspector' && $model['model'] === 'mystery-model')));
});

test('the index reports no unpriced models when no hard cap is set', function (): void {
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openai', 'mystery-model')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page->where('unpricedModels', []));
});

test('admin can route chat, titles, sub-agents and the price updater through OpenRouter', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-4.5']);
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'x-ai/grok-4']);
    AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => [
                'chat' => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5'],
                'title' => ['provider' => 'openrouter', 'model' => 'auto'],
                'file_inspector' => ['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-4.5'],
                'stuck_download_investigator' => ['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-4.5'],
                'price_updater' => ['provider' => 'openrouter', 'model' => 'x-ai/grok-4'],
            ],
            'failover' => ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5'],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.ai-models.index'));

    expect(aiModelsResolvedPair(AiTask::Chat))->toBe(['openrouter', 'anthropic/claude-sonnet-5'])
        ->and(aiModelsResolvedPair(AiTask::Title))->toBe(['openrouter', 'anthropic/claude-haiku-4.5'])
        ->and(aiModelsResolvedPair(AiTask::FileInspector))->toBe(['openrouter', 'anthropic/claude-haiku-4.5'])
        ->and(aiModelsResolvedPair(AiTask::StuckDownloadInvestigator))->toBe(['openrouter', 'anthropic/claude-haiku-4.5'])
        ->and(aiModelsResolvedPair(AiTask::PriceUpdater))->toBe(['openrouter', 'x-ai/grok-4'])
        ->and(resolve(TaskModelResolver::class)->failover())->toBe(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);
});

test('the decision task saves a provider with its model and reasoning', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'openai/gpt-5-mini']);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['decision' => ['provider' => 'openrouter', 'model' => 'openai/gpt-5-mini', 'reasoning' => 'high']],
        ]))
        ->assertSessionHasNoErrors();

    $resolvedSelection = resolve(TaskModelResolver::class)->resolve(AiTask::Decision);

    expect([$resolvedSelection->provider, $resolvedSelection->model, $resolvedSelection->reasoning])
        ->toBe(['openrouter', 'openai/gpt-5-mini', AiReasoningLevel::High]);
});

test('blank sub-agent and price updater selections clear back to the chat selection', function (): void {
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('anthropic', 'claude-sonnet-5-5')->create();
    AiTaskModel::factory()->task(AiTask::StuckDownloadInvestigator)->selecting('anthropic', 'claude-sonnet-5-5')->create();
    AiTaskModel::factory()->task(AiTask::PriceUpdater)->selecting('openai', 'gpt-5-nano')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload())
        ->assertSessionHasNoErrors();

    expect(AiTaskModel::query()->whereIn('task', ['file_inspector', 'stuck_download_investigator', 'price_updater'])->exists())->toBeFalse()
        ->and(aiModelsResolvedPair(AiTask::FileInspector))->toBe(['openai', 'gpt-5.6-luna'])
        ->and(aiModelsResolvedPair(AiTask::StuckDownloadInvestigator))->toBe(['openai', 'gpt-5.6-luna'])
        ->and(aiModelsResolvedPair(AiTask::PriceUpdater))->toBe(['openai', 'gpt-5.6-luna']);
});

test('admin can set, change and clear the failover provider', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['failover' => ['provider' => 'anthropic', 'model' => null]]))
        ->assertSessionHasNoErrors();

    expect(resolve(TaskModelResolver::class)->failover())->toBe(['provider' => 'anthropic', 'model' => null]);

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['failover' => ['provider' => 'openrouter', 'model' => null]]))
        ->assertSessionHasNoErrors();

    expect(resolve(TaskModelResolver::class)->failover())->toBe(['provider' => 'openrouter', 'model' => null]);

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload())
        ->assertSessionHasNoErrors();

    expect(resolve(TaskModelResolver::class)->failover())->toBeNull();
});

test('changing the failover provider with a submitted model keeps it', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
    AiTaskModel::factory()->task(AiTask::Failover)->selecting('anthropic', 'claude-haiku-4-5')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['failover' => ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']]))
        ->assertSessionHasNoErrors();

    expect(resolve(TaskModelResolver::class)->failover())->toBe(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
});

test('a stale failover model is never kept without a provider', function (): void {
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiTaskModel::factory()->task(AiTask::Failover)->selecting('anthropic', 'claude-haiku-4-5')->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['failover' => ['provider' => 'openrouter', 'model' => null]]))
        ->assertSessionHasNoErrors();

    expect(resolve(TaskModelResolver::class)->failover())->toBe(['provider' => 'openrouter', 'model' => null]);

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['failover' => ['provider' => null, 'model' => 'claude-haiku-4-5']]))
        ->assertSessionHasErrors('failover.provider');

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['failover' => ['provider' => null, 'model' => null]]))
        ->assertSessionHasNoErrors();

    expect(resolve(TaskModelResolver::class)->failover())->toBeNull()
        ->and(AiTaskModel::query()->forTask(AiTask::Failover)->exists())->toBeFalse();
});

test('a provider without an API key is rejected', function (string $prefix): void {
    config()->set('ai.providers.mistral.key');
    $payload = aiModelsPayload();
    data_set($payload, $prefix.'.provider', 'mistral');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), $payload)
        ->assertSessionHasErrors($prefix.'.provider');
})->with(['tasks.chat.tiers.0', 'tasks.title.tiers.0', 'tasks.decision.tiers.0', 'tasks.file_inspector.tiers.0', 'tasks.price_updater.tiers.0', 'failover']);

test('a provider that cannot serve text is rejected', function (string $prefix): void {
    config()->set('ai.providers.jina.key', 'jina-test-key');
    $payload = aiModelsPayload();
    data_set($payload, $prefix.'.provider', 'jina');

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), $payload)
        ->assertSessionHasErrors($prefix.'.provider');
})->with(['tasks.chat.tiers.0', 'failover']);

/**
 * @param  array<string, mixed>  $tier
 * @return array<string, mixed>
 */
function aiModelsTier(?string $model, array $tier = []): array
{
    return ['provider' => $model === null ? null : 'openai', 'model' => $model, 'reasoning' => null, 'min_pool_percent' => null, 'min_pool_tokens' => null, ...$tier];
}

function aiModelsPooledLuna(): AiFreeUsagePool
{
    $pool = AiFreeUsagePool::factory()->unified(1_000_000)->overflow(FreePoolOverflowBehavior::Split)->create(['name' => 'Luna free']);
    AiModelPrice::query()->where('model', 'gpt-5.6-luna')->update(['free_usage_pool_id' => $pool->id]);

    return $pool;
}

test('a tier list is saved in order with its conditions', function (): void {
    aiModelsPooledLuna();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [
            aiModelsTier('gpt-5.6-luna', ['reasoning' => 'high', 'min_pool_percent' => 20, 'min_pool_tokens' => 50_000]),
            aiModelsTier('gpt-5-nano', ['reasoning' => 'low']),
        ]]]]))
        ->assertRedirect(route('admin.ai-models.index'));

    $tiers = AiTaskModel::query()->forTask(AiTask::Chat)->orderBy('position')->get();

    expect($tiers->map->only(['position', 'model', 'min_pool_percent', 'min_pool_tokens'])->all())->toBe([
        ['position' => 0, 'model' => 'gpt-5.6-luna', 'min_pool_percent' => 20, 'min_pool_tokens' => 50_000],
        ['position' => 1, 'model' => 'gpt-5-nano', 'min_pool_percent' => null, 'min_pool_tokens' => null],
    ]);
});

test('saving a shorter list renumbers and drops the rest', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [aiModelsTier('gpt-5-nano')]]]]))
        ->assertSessionHasNoErrors();

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->get()->map->only(['position', 'model'])->all())
        ->toBe([['position' => 0, 'model' => 'gpt-5-nano']]);
});

test('the last tier cannot have conditions', function (): void {
    aiModelsPooledLuna();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [
            aiModelsTier('gpt-5.6-luna', ['min_pool_percent' => 20]),
        ]]]]))
        ->assertSessionHasErrors(['tasks.chat.tiers.0.min_pool_percent' => 'The last tier always runs; remove its conditions.']);
});

test('a blank tier above the last one asks for a model', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [
            aiModelsTier(null),
            aiModelsTier('gpt-5-nano'),
        ]]]]))
        ->assertSessionHasErrors(['tasks.chat.tiers.0.model' => 'Pick a model for this tier.']);

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->exists())->toBeFalse();
});

test('conditions need a model that belongs to a free pool', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [
            aiModelsTier('gpt-5-nano', ['min_pool_tokens' => 1_000]),
            aiModelsTier('gpt-5.6-luna'),
        ]]]]))
        ->assertSessionHasErrors(['tasks.chat.tiers.0.min_pool_tokens' => 'gpt-5-nano has no free pool; remove the condition.']);
});

test('the title auto model cannot have conditions', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['title' => ['tiers' => [
            aiModelsTier('auto', ['min_pool_percent' => 10]),
            aiModelsTier('gpt-5-nano'),
        ]]]]))
        ->assertSessionHasErrors(['tasks.title.tiers.0.min_pool_percent' => 'Only a tier with a model can have conditions.']);
});

test('condition values are bounded', function (array $tier, string $field): void {
    aiModelsPooledLuna();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [
            aiModelsTier('gpt-5.6-luna', $tier),
            aiModelsTier('gpt-5-nano'),
        ]]]]))
        ->assertSessionHasErrors(sprintf('tasks.chat.tiers.0.%s', $field));
})->with([
    'percent zero' => [['min_pool_percent' => 0], 'min_pool_percent'],
    'percent over 100' => [['min_pool_percent' => 101], 'min_pool_percent'],
    'tokens zero' => [['min_pool_tokens' => 0], 'min_pool_tokens'],
    'tokens text' => [['min_pool_tokens' => 'lots'], 'min_pool_tokens'],
    'percent decimal' => [['min_pool_percent' => 12.5], 'min_pool_percent'],
    'tokens decimal' => [['min_pool_tokens' => 1000.5], 'min_pool_tokens'],
]);

test('event overrides save tier lists', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    aiModelsPooledLuna();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['event_overrides' => [['event_key' => 'sonarr:Download', 'tiers' => [
            aiModelsTier('gpt-5.6-luna', ['min_pool_percent' => 30]),
            aiModelsTier(null, ['reasoning' => 'low']),
        ]]]]))
        ->assertSessionHasNoErrors();

    expect(AiTaskModel::query()->where('scope', 'sonarr:Download')->orderBy('position')->get()->map->only(['position', 'model', 'reasoning', 'min_pool_percent'])->all())
        ->toBe([
            ['position' => 0, 'model' => 'gpt-5.6-luna', 'reasoning' => null, 'min_pool_percent' => 30],
            ['position' => 1, 'model' => null, 'reasoning' => AiReasoningLevel::Low, 'min_pool_percent' => null],
        ]);
});

test('the index lists tiers, model pools and the live tier', function (): void {
    aiModelsPooledLuna();
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'prompt_tokens' => 900_000, 'completion_tokens' => 0]);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.0.tiers.0.model', 'gpt-5.6-luna')
            ->where('tasks.0.tiers.0.min_pool_percent', 20)
            ->where('tasks.0.tiers.1.model', 'gpt-5-nano')
            ->where('tasks.0.resolved.model', 'gpt-5-nano')
            ->where('tasks.0.resolved.tier.position', 2)
            ->where('tasks.0.resolved.tier.reason', 'Tier 1: Luna free below 20% (10% left)')
            ->where('tasks.1.tiers', [['provider' => null, 'model' => null, 'reasoning' => null, 'min_pool_percent' => null, 'min_pool_tokens' => null]])
            ->where('modelPools', ['openai|gpt-5.6-luna' => ['name' => 'Luna free', 'percent_left' => 10, 'tokens_left' => 100_000]]));
});

test('a saved condition whose model lost its pool still renders and must be removed on save', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page->where('tasks.0.tiers.0.min_pool_percent', 20)->where('modelPools', []));

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [
            aiModelsTier('gpt-5.6-luna', ['min_pool_percent' => 20]),
            aiModelsTier('gpt-5-nano'),
        ]]]]))
        ->assertSessionHasErrors('tasks.chat.tiers.0.min_pool_percent');
});

test('the audit snapshot lists every tier with its conditions', function (): void {
    aiModelsPooledLuna();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload(['tasks' => ['chat' => ['tiers' => [
            aiModelsTier('gpt-5.6-luna', ['min_pool_percent' => 20]),
            aiModelsTier('gpt-5-nano'),
        ]]]]));

    $after = ActivityLog::query()->latest('id')->first()?->metadata;

    expect(json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->toContain('chat:default#0')->toContain('openai/gpt-5.6-luna · inherit · ≥20%')->toContain('chat:default#1');
});

test('the index still renders without model pools when pool status fails', function (): void {
    Exceptions::fake();
    aiModelsPooledLuna();
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();
    $this->mock(FreePoolAccounting::class, fn ($mock) => $mock->shouldReceive('status')->andThrow(new RuntimeException('accounting down')));
    app()->forgetScopedInstances();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('modelPools', []));

    Exceptions::assertReported(RuntimeException::class);
    Exceptions::assertReportedCount(1);
});

test('the index marks the benchmark judge as a single selection', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.7.task', 'benchmark_judge')
            ->where('tasks.7.allow_tiers', false)
            ->where('tasks.0.allow_tiers', true)
            ->where('tasks.7.inherits_chat', true)
            ->where('tasks.7.resolved.model', 'gpt-5.6-luna'));
});

test('the benchmark judge accepts only one tier', function (): void {
    $tier = ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => null, 'min_pool_percent' => null, 'min_pool_tokens' => null];

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['benchmark_judge' => ['tiers' => [$tier, [...$tier, 'model' => null, 'provider' => null]]]],
        ]))
        ->assertSessionHasErrors('tasks.benchmark_judge.tiers');
});

/**
 * @return array{BenchmarkRun, BenchmarkCase}
 */
function aiModelsPrefillRun(AiTask $aiTask = AiTask::Title): array
{
    $benchmarkCase = BenchmarkCase::factory()->forTask($aiTask)->create();
    $benchmarkRun = BenchmarkRun::factory()->completed()->withCandidates([
        ['provider' => 'openai', 'model' => 'gpt-5-nano', 'reasoning' => null],
        ['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'reasoning' => 'high'],
    ])->create(['name' => 'Title bake-off', 'case_ids' => [$benchmarkCase->id]]);
    BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 1)->scored(0.9, true)->create();

    return [$benchmarkRun, $benchmarkCase];
}

test('a benchmark candidate prefills the index without writing anything', function (): void {
    [$benchmarkRun] = aiModelsPrefillRun();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index', ['benchmark_run' => $benchmarkRun->id, 'candidate' => 1, 'task' => 'title']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('prefill.task', 'title')
            ->where('prefill.provider', 'openai')
            ->where('prefill.model', 'gpt-5.6-luna')
            ->where('prefill.reasoning', 'high')
            ->where('prefill.run_id', $benchmarkRun->id)
            ->where('prefill.run_name', 'Title bake-off'));

    expect(AiTaskModel::query()->count())->toBe(0);
});

test('a prefill for a candidate without reasoning carries none', function (): void {
    [$benchmarkRun, $benchmarkCase] = aiModelsPrefillRun(AiTask::Decision);
    BenchmarkResult::factory()->forCase($benchmarkRun, $benchmarkCase, 0)->scored(0.5, true)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index', ['benchmark_run' => $benchmarkRun->id, 'candidate' => 0, 'task' => 'decision']))
        ->assertInertia(fn ($page) => $page->where('prefill.reasoning', null)->where('prefill.model', 'gpt-5-nano'));
});

test('a task that inherits the chat model still gets the prefill prop', function (): void {
    expect(AiTask::Decision->inheritsChatModel())->toBeTrue();
    [$benchmarkRun] = aiModelsPrefillRun(AiTask::Decision);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index', ['benchmark_run' => $benchmarkRun->id, 'candidate' => 1, 'task' => 'decision']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tasks.2.task', 'decision')
            ->where('tasks.2.tiers.0.model', null)
            ->where('prefill.task', 'decision')
            ->where('prefill.model', 'gpt-5.6-luna'));

    expect(AiTaskModel::query()->count())->toBe(0);
});

test('the index has no prefill without a benchmark link', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page->where('prefill', null));
});

test('a stale benchmark link redirects to the plain page with a toast', function (array $query): void {
    [$benchmarkRun] = aiModelsPrefillRun();
    $query = array_replace(['benchmark_run' => $benchmarkRun->id, 'candidate' => 1, 'task' => 'title'], $query);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index', $query))
        ->assertRedirect(route('admin.ai-models.index'));

    expect(session('inertia.flash_data.toast.type'))->toBe('error')
        ->and((string) session('inertia.flash_data.toast.message'))->toBe('That benchmark candidate is no longer available.');
    expect(AiTaskModel::query()->count())->toBe(0);
})->with([
    'unknown run' => [['benchmark_run' => 999_999]],
    'candidate out of range' => [['candidate' => 5]],
    'candidate not numeric' => [['candidate' => 'x']],
    'task not in the run' => [['task' => 'chat']],
    'failover task' => [['task' => 'failover']],
    'unknown task' => [['task' => 'nope']],
]);

test('the decision verifier row warns when it resolves to the decision agent model', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tasks.3.task', 'decision_verifier')
            ->where('tasks.3.warning', 'Runs on the same model as the Decision agent, so its second opinion is less independent.')
            ->where('tasks.2.warning', null));
});

test('the warning clears once the verifier runs on another model', function (): void {
    AiTaskModel::factory()->task(AiTask::DecisionVerifier)->selecting('openai', 'gpt-verifier')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-models.index'))
        ->assertInertia(fn ($page) => $page->where('tasks.3.warning', null));
});
