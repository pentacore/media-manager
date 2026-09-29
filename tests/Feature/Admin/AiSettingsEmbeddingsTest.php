<?php

declare(strict_types=1);

use App\Jobs\ReembedLibrary;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Cache::flush();
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
});

test('index exposes the embeddings selection and whether it is stale', function (): void {
    resolve(AiSettings::class)->setEmbeddingsModel('text-embedding-3-large');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-settings.index'))
        ->assertInertia(fn ($page) => $page
            ->where('settings.embeddings_provider', 'openai')
            ->where('settings.embeddings_model', 'text-embedding-3-large')
            ->where('embeddings.stale', true)
            ->where('embeddings.reembedding', false)
            ->where('embeddingsProviders', fn ($providers): bool => collect($providers)->pluck('value')->contains('openrouter')));
});

test('index reflects a re-embed chain that is currently running', function (): void {
    Cache::put(ReembedLibrary::RUNNING_CACHE_KEY, (string) Str::uuid7(), ReembedLibrary::RUNNING_CACHE_TTL);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-settings.index'))
        ->assertInertia(fn ($page) => $page->where('embeddings.reembedding', true));
});

test('embeddings provider options use human-friendly labels, not ucfirst', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-settings.index'))
        ->assertInertia(fn ($page) => $page
            ->where('embeddingsProviders', function ($providers): bool {
                $labels = collect($providers)->pluck('label', 'value');

                return $labels->get('openai') === 'OpenAI'
                    && $labels->get('openrouter') === 'OpenRouter';
            }));
});

test('admin can change the embeddings provider and model', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'embeddings_provider' => 'openrouter',
            'embeddings_model' => 'openai/text-embedding-3-small',
        ])
        ->assertRedirect();

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->embeddingsProvider())->toBe('openrouter')
        ->and($aiSettings->embeddingsModel())->toBe('openai/text-embedding-3-small');
});

test('a provider without embeddings support is rejected', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'embeddings_provider' => 'anthropic',
        ])
        ->assertSessionHasErrors('embeddings_provider');
});

test('admin can queue a library re-embed', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-settings.reembed'))
        ->assertRedirect(route('admin.ai-settings.index'));

    Queue::assertPushed(ReembedLibrary::class);
});

test('the claimed run token is passed to the dispatched job', function (): void {
    Queue::fake();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-settings.reembed'))
        ->assertRedirect(route('admin.ai-settings.index'));

    $claimedToken = Cache::get(ReembedLibrary::RUNNING_CACHE_KEY);

    expect($claimedToken)->not->toBeNull();

    Queue::assertPushed(ReembedLibrary::class, fn (ReembedLibrary $job): bool => $job->runToken === $claimedToken);
});

test('a re-embed request while one is already running dispatches nothing and says so', function (): void {
    Queue::fake();
    Cache::put(ReembedLibrary::RUNNING_CACHE_KEY, (string) Str::uuid7(), ReembedLibrary::RUNNING_CACHE_TTL);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-settings.reembed'))
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'info')
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'A library re-embed is already running.',
        );

    Queue::assertNotPushed(ReembedLibrary::class);
});

test('members cannot queue a re-embed', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->post(route('admin.ai-settings.reembed'))
        ->assertForbidden();
});
