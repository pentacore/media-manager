<?php

declare(strict_types=1);

use App\Ai\Agents\CommandAgent;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('guests cannot access AI usage', function (): void {
    $this->get(route('admin.ai-usage.index'))
        ->assertRedirect(route('login'));
});

test('non-admin cannot access AI usage', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.ai-usage.index'))
        ->assertForbidden();
});

test('admin sees the dashboard with current totals', function (): void {
    $admin = User::factory()->admin()->create();

    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
    ]);

    AiUsageRecord::create([
        'invocation_id' => 'inv-1',
        'agent_class' => CommandAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 500_000,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 3,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiUsage/Index')
            ->where('window', '7d')
            ->where('totals.total_invocations', 1)
            ->where('totals.total_tool_calls', 3)
            ->has('by_agent')
            ->has('by_model')
            ->has('by_provider')
            ->has('recent')
        );
});

test('window query parameter filters the time range', function (): void {
    $admin = User::factory()->admin()->create();

    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'old',
        'agent_class' => CommandAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 1_000,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
        'created_at' => CarbonImmutable::now()->subDays(3),
        'updated_at' => CarbonImmutable::now()->subDays(3),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index', ['window' => '24h']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('window', '24h')
            ->where('totals.total_invocations', 0)
        );

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index', ['window' => '7d']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('window', '7d')
            ->where('totals.total_invocations', 1)
        );
});

test('invalid window falls back to 7d', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index', ['window' => 'forever']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('window', '7d'));
});

test('scenario query param adds projected props to the page', function (): void {
    $admin = User::factory()->admin()->create();

    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
    ]);

    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'inv-x',
        'agent_class' => CommandAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index', [
            'scenario' => [
                'input' => 1.00,
                'output' => 5.00,
                'cache_read' => 0,
                'cache_write' => 0,
                'reasoning' => 0,
            ],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scenario.input', 1)
            ->where('totals.total_cost', fn (string $cost): bool => abs((float) $cost - 0.40) < 0.0001)
            ->has('scenario_totals')
            ->where('scenario_totals.total_cost', fn (string $cost): bool => abs((float) $cost - 1.00) < 0.0001)
            ->has('scenario_by_agent')
            ->has('scenario_by_model')
            ->has('scenario_by_provider')
            ->has('scenario_recent')
        );
});

test('malformed scenario is ignored', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index', [
            'scenario' => ['input' => 'not-a-number'],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scenario', null)
            ->missing('scenario_totals')
        );
});

test('priced_models is included in page props', function (): void {
    $admin = User::factory()->admin()->create();

    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('priced_models', fn ($models) => $models->etc()));
});
