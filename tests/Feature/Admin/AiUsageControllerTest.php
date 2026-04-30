<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
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
        'agent_class' => MediaAgent::class,
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
            ->has('by_model')
            ->has('by_provider')
            ->has('recent')
        );
});

test('window query parameter filters the time range', function (): void {
    $admin = User::factory()->admin()->create();

    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'old',
        'agent_class' => MediaAgent::class,
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

test('window=today narrows recent invocations to the current local day', function (): void {
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

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 29, 14, 30));

    DB::table('ai_usage_records')->insert([
        [
            'invocation_id' => 'today-row',
            'agent_class' => MediaAgent::class,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'prompt_tokens' => 1, 'completion_tokens' => 1,
            'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0,
            'tool_calls_count' => 0, 'status' => 'success',
            'created_at' => CarbonImmutable::create(2026, 4, 29, 1, 0),
            'updated_at' => CarbonImmutable::create(2026, 4, 29, 1, 0),
        ],
        [
            'invocation_id' => 'yesterday-row',
            'agent_class' => MediaAgent::class,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'prompt_tokens' => 1, 'completion_tokens' => 1,
            'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0,
            'tool_calls_count' => 0, 'status' => 'success',
            'created_at' => CarbonImmutable::create(2026, 4, 28, 23, 30),
            'updated_at' => CarbonImmutable::create(2026, 4, 28, 23, 30),
        ],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-usage.index', ['window' => 'today']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('window', 'today')
            ->where('windows.0', 'today')
            ->where('totals.total_invocations', 1)
        );

    CarbonImmutable::setTestNow();
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
        'agent_class' => MediaAgent::class,
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

test('show returns invocation detail with breakdown using the row snapshot', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->member()->create(['name' => 'Stella']);

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-detail',
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 500_000,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
        'price_source' => 'live',
        'user_id' => $owner->id,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.ai-usage.show', $record))
        ->assertOk()
        ->assertJsonPath('record.invocation_id', 'inv-detail')
        ->assertJsonPath('user.name', 'Stella')
        ->assertJsonPath('rates.source', 'snapshot')
        ->assertJsonPath('rates.input_per_mtok', fn ($v): bool => abs((float) $v - 0.40) < 0.0001)
        ->assertJsonPath('total_cost', fn ($v): bool => abs((float) $v - 1.20) < 0.0001);
});

test('show exposes the persisted assistant response_text', function (): void {
    $admin = User::factory()->admin()->create();

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-text',
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 100,
        'completion_tokens' => 50,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'response_text' => 'Hello from the assistant.',
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.ai-usage.show', $record))
        ->assertOk()
        ->assertJsonPath('record.response_text', 'Hello from the assistant.');
});

test('show falls back to the live catalog rate when no snapshot exists', function (): void {
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

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-bare',
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.ai-usage.show', $record))
        ->assertOk()
        ->assertJsonPath('rates.source', 'catalog')
        ->assertJsonPath('total_cost', fn ($v): bool => abs((float) $v - 0.40) < 0.0001);
});

test('show flags fully unpriced rows', function (): void {
    $admin = User::factory()->admin()->create();

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-unp',
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'mystery-model',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.ai-usage.show', $record))
        ->assertOk()
        ->assertJsonPath('rates.source', 'unpriced')
        ->assertJsonPath('total_cost', 0);
});

test('show returns scenario breakdown when scenario rates are provided', function (): void {
    $admin = User::factory()->admin()->create();

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-scn',
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->getJson(route('admin.ai-usage.show', [
            'aiUsageRecord' => $record,
            'scenario' => [
                'input' => 2.00,
                'output' => 5.00,
                'cache_read' => 0,
                'cache_write' => 0,
                'reasoning' => 0,
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('scenario_total_cost', fn ($v): bool => abs((float) $v - 2.00) < 0.0001);
});

test('assignPrice copies rates from the picked catalog entry', function (): void {
    $admin = User::factory()->admin()->create();

    AiModelPrice::create([
        'provider' => 'anthropic',
        'model' => 'claude-haiku-4-5',
        'input_per_mtok' => 1.00,
        'output_per_mtok' => 5.00,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 1.25,
        'reasoning_per_mtok' => 0,
    ]);

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-asn',
        'agent_class' => MediaAgent::class,
        'provider' => 'anthropic',
        'model' => 'claude-haiku-4-5',
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->from(route('admin.ai-usage.index'))
        ->post(route('admin.ai-usage.assign-price', $record), [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
        ])
        ->assertRedirect(route('admin.ai-usage.index'));

    $record->refresh();

    expect((float) $record->input_per_mtok)->toBe(1.00)
        ->and((float) $record->output_per_mtok)->toBe(5.00)
        ->and($record->price_source)->toBe('assigned');
});

test('assignPrice rejects an unknown provider+model pair', function (): void {
    $admin = User::factory()->admin()->create();

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-bad',
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.ai-usage.assign-price', $record), [
            'provider' => 'nope',
            'model' => 'nope',
        ])
        ->assertNotFound();
});

test('non-admin cannot drill into invocation detail or assign price', function (): void {
    $member = User::factory()->member()->create();

    $record = AiUsageRecord::create([
        'invocation_id' => 'inv-priv',
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
    ]);

    $this->actingAs($member)
        ->getJson(route('admin.ai-usage.show', $record))
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('admin.ai-usage.assign-price', $record), [
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
        ])
        ->assertForbidden();
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
