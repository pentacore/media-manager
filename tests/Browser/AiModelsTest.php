<?php

declare(strict_types=1);

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\User;
use App\Settings\DecisionAgentSettings;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'supports_reasoning' => true]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-4.1', 'supports_reasoning' => false]);
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
});

test('admin can route chat through an OpenRouter model with a reasoning level', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-task="chat"] [data-model-select] button')
        ->click('anthropic/claude-sonnet-5')
        ->click('[data-task="chat"] [data-reasoning-select] button')
        ->click('[data-reasoning-option="high"]')
        ->click('Save models')
        ->assertSee('AI models updated.')
        ->assertSeeIn('[data-task="chat"] [data-model-select]', 'anthropic/claude-sonnet-5')
        ->assertSeeIn('[data-task="chat"] [data-resolved]', 'openrouter · anthropic/claude-sonnet-5 · High');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->first())
        ->provider->toBe('openrouter')
        ->model->toBe('anthropic/claude-sonnet-5')
        ->reasoning->toBe(AiReasoningLevel::High);
});

test('inherited tasks show what they resolve to', function (): void {
    config()->set('mediamanager.ai.pricing.updater_model', '');
    config()->set('mediamanager.ai.sub_agent_model', '');
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-task="file_inspector"] [data-model-select]')
        ->assertVisible('[data-task="stuck_download_investigator"] [data-model-select]')
        ->assertSeeIn('[data-task="file_inspector"] [data-resolved]', 'openai · gpt-5.6-luna')
        ->assertSeeIn('[data-task="price_updater"] [data-resolved]', 'openai · gpt-5.6-luna');
});

test('the reasoning picker is disabled for a model that does not reason', function (): void {
    AiTaskModel::factory()->task(AiTask::Title)->selecting('openai', 'gpt-4.1')->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-task="title"] [data-reasoning-hint]', "Model doesn't reason")
        ->assertDisabled('[data-task="title"] [data-reasoning-select] button');
});

test('admin can pick a dedicated price updater model', function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-updater']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-task="price_updater"] [data-model-select]', 'Same as chat')
        ->click('[data-task="price_updater"] [data-model-select] button')
        ->click('[role="option"][aria-label="gpt-updater"]')
        ->assertSeeIn('[data-task="price_updater"] [data-model-select]', 'gpt-updater')
        ->click('Save models')
        ->assertSee('AI models updated.')
        ->assertSeeIn('[data-task="price_updater"] [data-model-select]', 'gpt-updater')
        ->assertSeeIn('[data-task="price_updater"] [data-resolved]', 'openai · gpt-updater');

    expect(AiTaskModel::query()->forTask(AiTask::PriceUpdater)->first())
        ->provider->toBe('openai')
        ->model->toBe('gpt-updater');
});

test('admin can add and remove a decision event override', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-add-event-override] button')
        ->click('sonarr:Download')
        ->click('[data-event-override="sonarr:Download"] [data-reasoning-select] button')
        ->click('[data-reasoning-option="none"]')
        ->click('Save models')
        ->assertSee('AI models updated.');

    expect(AiTaskModel::query()->where('scope', 'sonarr:Download')->first()?->reasoning)->toBe(AiReasoningLevel::None);

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-event-override="sonarr:Download"] [data-remove-override]')
        ->assertMissing('[data-event-override="sonarr:Download"]')
        ->click('Save models')
        ->assertSee('AI models updated.');

    expect(AiTaskModel::query()->where('scope', 'sonarr:Download')->exists())->toBeFalse();
});

test('an override for a disabled event is flagged', function (): void {
    AiTaskModel::factory()->event('radarr:Grab')->reasoning(AiReasoningLevel::Low)->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-event-override="radarr:Grab"]', 'Event not enabled');
});

test('the old settings pages link to AI Models', function (string $routeName): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route($routeName, absolute: false))
        ->assertNoSmoke()
        ->click('[data-ai-models-link]')
        ->assertPathIs(route('admin.ai-models.index', absolute: false));
})->with(['admin.ai-settings.index', 'admin.decision-agent.index']);
