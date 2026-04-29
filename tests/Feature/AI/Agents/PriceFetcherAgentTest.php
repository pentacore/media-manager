<?php

declare(strict_types=1);

use App\Ai\Agents\PriceFetcherAgent;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Ai\Tools\PriceFetcher\WebFetchTool;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use Laravel\Ai\Contracts\HasTools;

test('agent declares both pricing tools', function (): void {
    $agent = new PriceFetcherAgent;

    expect($agent)->toBeInstanceOf(HasTools::class);

    $names = collect($agent->tools())->map(fn ($tool): string => $tool::class)->all();
    expect($names)
        ->toContain(WebFetchTool::class)
        ->toContain(UpsertModelPriceTool::class);
});

test('admin refresh endpoint queues the agent and toasts the queued state', function (): void {
    PriceFetcherAgent::fake(['Refreshed 6 OpenAI models, skipped DeepSeek (fetch_failed).']);

    $admin = User::factory()->admin()->create();

    // Sync queue (phpunit.xml) runs the job inline, so the agent still
    // executes inside this POST. The flashed toast now reports "queued"
    // because the controller no longer waits for the agent — progress is
    // surfaced via the admin.ai-prices broadcast channel instead.
    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', fn (string $message): bool => str_contains($message, 'queued'));
});

test('non-admins cannot trigger a price refresh', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->post(route('admin.ai-prices.refresh'))
        ->assertForbidden();
});

test('refresh endpoint reports count delta against the database', function (): void {
    PriceFetcherAgent::fake(['done']);

    AiModelPrice::factory()->count(2)->create();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'));

    // The fake doesn't insert rows, so :added should be zero — confirms we
    // aren't crashing on an empty agent run.
    expect(AiModelPrice::query()->count())->toBe(2);
});

test('refresh attributes AI usage to the admin who triggered it', function (): void {
    PriceFetcherAgent::fake(['done']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'));

    $record = AiUsageRecord::query()
        ->where('agent_class', PriceFetcherAgent::class)
        ->latest('id')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->user_id)->toBe($admin->id);
});

test('refresh captures a price snapshot onto the usage row', function (): void {
    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.50,
        'output_per_mtok' => 2.00,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.00,
    ]);

    PriceFetcherAgent::fake(['done']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'));

    $aiUsageRecord = AiUsageRecord::query()
        ->where('agent_class', PriceFetcherAgent::class)
        ->latest('id')
        ->firstOrFail();

    expect($aiUsageRecord->price_source)->toBe('live')
        ->and((float) $aiUsageRecord->input_per_mtok)->toBe(0.50)
        ->and((float) $aiUsageRecord->reasoning_per_mtok)->toBe(1.00);
});

test('refresh leaves snapshot null when the agent model is unpriced', function (): void {
    PriceFetcherAgent::fake(['done']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'));

    $aiUsageRecord = AiUsageRecord::query()
        ->where('agent_class', PriceFetcherAgent::class)
        ->latest('id')
        ->firstOrFail();

    expect($aiUsageRecord->price_source)->toBeNull()
        ->and($aiUsageRecord->input_per_mtok)->toBeNull();
});
