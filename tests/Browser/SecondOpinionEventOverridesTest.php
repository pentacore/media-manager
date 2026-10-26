<?php

declare(strict_types=1);

use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Models\User;
use App\Settings\DecisionAgentSettings;

function secondOpinionOverridesPage(): string
{
    return route('admin.decision-agent.index', absolute: false);
}

function secondOpinionOverride(string $eventKey, ?SecondOpinionMode $secondOpinionMode, ?SecondOpinionEngine $secondOpinionEngine = null, ?float $threshold = null): void
{
    resolve(DecisionAgentSettings::class)->setSecondOpinionEventOverrides([$eventKey => ['mode' => $secondOpinionMode, 'engine' => $secondOpinionEngine, 'jev_threshold' => $threshold]]);
}

test('an admin adds a per-event override and picks its mode', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    $this->actingAs(User::factory()->admin()->create());

    visit(secondOpinionOverridesPage())
        ->assertNoSmoke()
        ->assertSeeIn('[data-second-opinion-event-overrides]', "An event's mode replaces the per-action approval rules for actions proposed on that event.")
        ->click('[data-add-second-opinion-override] button')
        ->click('[data-second-opinion-override-option="sonarr:Download"]')
        ->click('[data-second-opinion-override="sonarr:Download"] [data-override-mode]')
        ->click('[data-override-mode-option="reject"]')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionModeOverride('sonarr:Download'))->toBe(SecondOpinionMode::Reject);
});

test('a jev engine for an event shows its threshold, and clearing it inherits the global one', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    secondOpinionOverride('sonarr:Download', SecondOpinionMode::Hold);
    $this->actingAs(User::factory()->admin()->create());

    visit(secondOpinionOverridesPage())
        ->assertNoSmoke()
        ->assertMissing('[data-second-opinion-override="sonarr:Download"] [data-override-threshold]')
        ->click('[data-second-opinion-override="sonarr:Download"] [data-override-engine]')
        ->click('[data-override-engine-option="jev"]')
        ->fill('[data-second-opinion-override="sonarr:Download"] [data-override-threshold]', '0.6')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEngine('sonarr:Download'))->toBe(SecondOpinionEngine::Jev)
        ->and(resolve(DecisionAgentSettings::class)->secondOpinionJevThreshold('sonarr:Download'))->toBe(0.6);

    visit(secondOpinionOverridesPage())
        ->assertNoSmoke()
        ->fill('[data-second-opinion-override="sonarr:Download"] [data-override-threshold]', '')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEventOverrides()['sonarr:Download']['jev_threshold'])->toBeNull()
        ->and(resolve(DecisionAgentSettings::class)->secondOpinionJevThreshold('sonarr:Download'))->toBe(0.8);
});

test('removing an override drops it on save', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    secondOpinionOverride('sonarr:Download', SecondOpinionMode::Off);
    $this->actingAs(User::factory()->admin()->create());

    visit(secondOpinionOverridesPage())
        ->assertNoSmoke()
        ->click('[data-second-opinion-override="sonarr:Download"] [data-remove-second-opinion-override]')
        ->assertMissing('[data-second-opinion-override="sonarr:Download"]')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEventOverrides())->toBe([]);
});

test('an override for an event that is no longer enabled is flagged, links to its model and is kept', function (): void {
    secondOpinionOverride('radarr:Grab', SecondOpinionMode::Off);
    $this->actingAs(User::factory()->admin()->create());

    visit(secondOpinionOverridesPage())
        ->assertNoSmoke()
        ->assertSeeIn('[data-second-opinion-override="radarr:Grab"] [data-override-not-enabled]', 'Event not enabled')
        ->assertAttributeContains('[data-second-opinion-override="radarr:Grab"] [data-override-model-link]', 'href', '/admin/ai-models#event-overrides-decision_verifier')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionModeOverride('radarr:Grab'))->toBe(SecondOpinionMode::Off);
});

test('the per-event overrides fit a phone screen', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    secondOpinionOverride('sonarr:Download', SecondOpinionMode::Hold, SecondOpinionEngine::JevThenLlm, 0.7);
    $this->actingAs(User::factory()->admin()->create());

    visit(secondOpinionOverridesPage())->on()->mobile()
        ->assertNoSmoke()
        ->assertVisible('[data-second-opinion-override="sonarr:Download"] [data-override-threshold]')
        ->assertScript(phoneOverflowScript('main'), '');
});

test('switching an event to the llm engine drops its jev threshold', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    secondOpinionOverride('sonarr:Download', SecondOpinionMode::Hold, SecondOpinionEngine::JevThenLlm, 0.7);
    $this->actingAs(User::factory()->admin()->create());

    visit(secondOpinionOverridesPage())
        ->assertNoSmoke()
        ->click('[data-second-opinion-override="sonarr:Download"] [data-override-engine]')
        ->click('[data-override-engine-option="llm"]')
        ->assertMissing('[data-second-opinion-override="sonarr:Download"] [data-override-threshold]')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEventOverrides()['sonarr:Download'])
        ->toBe(['mode' => SecondOpinionMode::Hold, 'engine' => SecondOpinionEngine::Llm, 'jev_threshold' => null]);
});
