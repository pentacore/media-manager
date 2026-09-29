<?php

declare(strict_types=1);

use App\Jobs\ReembedLibrary;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => resolve(AiSettings::class)->model()]);
});

test('changing the embeddings model shows the stale banner and re-embeds on request', function (): void {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-embeddings-stale]')
        ->fill('embeddings_model', 'text-embedding-3-large')
        ->click('Save settings')
        ->assertSee('AI settings updated.')
        ->assertVisible('[data-embeddings-stale]')
        ->click('[data-embeddings-reembed]')
        ->assertSee('Library re-embed queued.');

    Queue::assertPushed(ReembedLibrary::class);
});

test('a running re-embed shows the running status and disables the button', function (): void {
    resolve(AiSettings::class)->setEmbeddingsModel('text-embedding-3-large');
    Cache::put(ReembedLibrary::RUNNING_CACHE_KEY, true, ReembedLibrary::RUNNING_CACHE_TTL);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-embeddings-reembedding]')
        ->assertSeeIn('[data-embeddings-reembedding]', 'Re-embedding the library…')
        ->assertDisabled('[data-embeddings-reembed]');
});
