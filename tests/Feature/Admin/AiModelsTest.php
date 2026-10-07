<?php

declare(strict_types=1);

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\ActivityLog;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\User;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Cache;

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
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function aiModelsPayload(array $overrides = []): array
{
    $blank = ['provider' => null, 'model' => null, 'reasoning' => null];

    return array_replace_recursive([
        'tasks' => [
            'chat' => ['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'reasoning' => 'medium'],
            'title' => $blank,
            'decision' => $blank,
            'file_inspector' => $blank,
            'stuck_download_investigator' => $blank,
            'price_updater' => $blank,
        ],
        'failover' => ['provider' => null, 'model' => null],
        'event_overrides' => [],
    ], $overrides);
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
            ->where('tasks.0.model', 'gpt-5.6-luna')
            ->where('tasks.0.resolved.reasoning', 'medium')
            ->where('tasks.2.task', 'decision')
            ->where('tasks.2.model', null)
            ->where('tasks.2.resolved.model', 'gpt-5.6-luna')
            ->has('models.openai')
            ->has('reasoningLevels')
            ->has('tasks', 6));
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
        ->assertSessionHasErrors('tasks.chat.model');

    expect(AiTaskModel::query()->count())->toBe(0);
});

test('a model without a provider is rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-models.update'), aiModelsPayload([
            'tasks' => ['price_updater' => ['provider' => null, 'model' => 'gpt-5-nano']],
        ]))
        ->assertSessionHasErrors('tasks.price_updater.provider');
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
        'model' => 'gpt-5-nano', 'reasoning_level' => 'low',
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
        ->assertSessionHasErrors('tasks.chat.model');

    $this->actingAs($admin)
        ->put(route('admin.ai-models.update'), aiModelsPayload(['event_overrides' => ['not-an-array']]))
        ->assertSessionHasErrors('event_overrides.0.event_key');
});
