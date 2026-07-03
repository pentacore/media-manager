<?php

declare(strict_types=1);

use App\Enums\AiMode;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
});

test('guests cannot access AI settings', function (): void {
    $this->get(route('admin.ai-settings.index'))
        ->assertRedirect(route('login'));
});

test('non-admin cannot access AI settings', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.ai-settings.index'))
        ->assertForbidden();
});

test('admin sees current settings on index', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(AiSettings::class)->setModel('claude-haiku-4-5');

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.model', 'claude-haiku-4-5')
            ->has('modes')
        );
});

test('index shows the raw auto sentinel while the accessor resolves a concrete model', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(AiSettings::class)->setTitleModel(AiSettings::AUTO_MODEL);

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.title_model', AiSettings::AUTO_MODEL)
        );

    $aiSettings = resolve(AiSettings::class);
    expect($aiSettings->rawTitleModel())->toBe(AiSettings::AUTO_MODEL);
    expect($aiSettings->titleModel())->not->toBe(AiSettings::AUTO_MODEL);
});

test('admin can update settings', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'advisory',
            'model' => 'gemini-3-flash-preview',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'medium',
        ])
        ->assertRedirect(route('admin.ai-settings.index'));

    $aiSettings = resolve(AiSettings::class);
    expect($aiSettings->mode())->toBe(AiMode::Advisory);
    expect($aiSettings->model())->toBe('gemini-3-flash-preview');
    expect($aiSettings->titleModel())->toBe('gpt-5.4-nano');
    expect($aiSettings->advisorReasoningLevel())->toBe('medium');
});

test('admin can set and clear the failover provider', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'failover_provider' => 'anthropic',
        ])
        ->assertRedirect(route('admin.ai-settings.index'));

    expect(resolve(AiSettings::class)->failoverProvider())->toBe(Lab::Anthropic);

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'failover_provider' => 'none',
        ])
        ->assertRedirect(route('admin.ai-settings.index'));

    expect(resolve(AiSettings::class)->failoverProvider())->toBeNull();
});

test('update rejects an unknown failover provider', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'failover_provider' => 'cohere',
        ])
        ->assertSessionHasErrors('failover_provider');
});

test('update validates mode is a known value', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'enthusiastic',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
        ])
        ->assertSessionHasErrors('mode');
});

test('update requires mode, model, and title_model', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [])
        ->assertSessionHasErrors(['mode', 'model', 'title_model', 'advisor_reasoning_level']);
});
