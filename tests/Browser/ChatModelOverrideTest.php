<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\ChatTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-nano']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::Medium)->create();
});

test('the picker shows the defaults and an override sticks to the new conversation', function (): void {
    MediaAgent::fake(['Hello there']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-chat-model-chip]', 'gpt-5.6-luna · Medium')
        ->assertAttribute('[data-chat-model-chip]', 'data-overridden', 'false')
        ->click('[data-chat-model-chip]')
        ->click('[data-chat-model-picker] [data-reasoning-select] button')
        ->click('[data-reasoning-option="high"]')
        ->assertAttribute('[data-chat-model-chip]', 'data-overridden', 'true')
        ->assertSeeIn('[data-chat-model-chip]', 'gpt-5.6-luna · High')
        ->keys('[data-chat-model-picker] [data-reasoning-select] button', 'Escape')
        ->assertMissing('[data-chat-model-picker]')
        ->fill('[data-chat-input]', 'Hi')
        ->keys('[data-chat-input]', 'Enter')
        ->assertSee('Hello there')
        ->assertSeeIn('[data-answered-by]', 'gpt-5.6-luna · High');

    expect(DB::table('agent_conversations')->latest('created_at')->value('reasoning'))->toBe('high');
});

test('reset returns the conversation to the defaults', function (): void {
    MediaAgent::fake(['One', 'Two']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-chat-model-chip]')
        ->click('[data-chat-model-picker] [data-reasoning-select] button')
        ->click('[data-reasoning-option="low"]')
        ->keys('[data-chat-model-picker] [data-reasoning-select] button', 'Escape')
        ->assertMissing('[data-chat-model-picker]')
        ->fill('[data-chat-input]', 'Hi')
        ->keys('[data-chat-input]', 'Enter')
        ->assertSee('One')
        ->assertSeeIn('[data-answered-by]', 'gpt-5.6-luna · Low')
        ->click('[data-chat-model-chip]')
        ->click('[data-chat-model-reset]')
        ->assertAttribute('[data-chat-model-chip]', 'data-overridden', 'false')
        // The chip stays disabled until the PATCH has been answered.
        ->assertEnabled('[data-chat-model-chip]')
        ->assertSeeIn('[data-chat-model-chip]', 'gpt-5.6-luna · Medium');

    expect(DB::table('agent_conversations')->latest('created_at')->value('reasoning'))->toBeNull();
});

test('a template preset fills the picker for a new conversation', function (): void {
    $admin = User::factory()->admin()->create();
    $chatTemplate = ChatTemplate::factory()->for($admin)->pinned()->create([
        'name' => 'Deep check',
        'body' => 'Check everything',
        'model_provider' => 'openai',
        'model' => 'gpt-5-nano',
        'reasoning' => AiReasoningLevel::High,
    ]);
    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->assertAttribute('[data-chat-model-chip]', 'data-overridden', 'false')
        ->click(sprintf('[data-template-chip="%d"]', $chatTemplate->id))
        ->assertValue('[data-chat-input]', 'Check everything')
        ->assertSeeIn('[data-chat-model-chip]', 'gpt-5-nano · High')
        ->assertAttribute('[data-chat-model-chip]', 'data-overridden', 'true');
});

test('an existing conversation shows its saved override and a reasoning change keeps its model', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'title' => 'Nano chat',
        'model_provider' => 'openai',
        'model' => 'gpt-5-nano',
        'reasoning' => 'high',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->click('[data-conversation-picker]')
        ->click(sprintf('[data-conversation-id="%s"]', $conversationId))
        ->assertSeeIn('[data-chat-model-chip]', 'gpt-5-nano · High')
        ->assertAttribute('[data-chat-model-chip]', 'data-overridden', 'true')
        ->click('[data-chat-model-chip]')
        ->click('[data-chat-model-picker] [data-reasoning-select] button')
        ->click('[data-reasoning-option="low"]')
        ->assertSeeIn('[data-chat-model-chip]', 'gpt-5-nano · Low')
        // The chip stays disabled until the PATCH has been answered.
        ->assertEnabled('[data-chat-model-chip]');

    $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();

    expect($conversation->model_provider)->toBe('openai')
        ->and($conversation->model)->toBe('gpt-5-nano')
        ->and($conversation->reasoning)->toBe('low');
});
