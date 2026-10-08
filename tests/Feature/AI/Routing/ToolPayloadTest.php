<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Routing\ToolGroup;
use App\Ai\Routing\ToolPayload;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Models\User;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ToolSearch;

test('an all-OpenAI/Anthropic chain defers non-core tools behind one ToolSearch', function (): void {
    config()->set('ai.default', 'openai');
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();

    $tools = [...(new MediaAgent)->tools()];
    $payload = resolve(ToolPayload::class)->build($tools);
    $searches = array_values(array_filter($payload, fn (object $tool): bool => $tool instanceof ToolSearch));

    expect($searches)->toHaveCount(1)
        ->and(collect($payload)->reject(fn (object $tool): bool => $tool instanceof ToolSearch)->map(fn (object $tool): string => $tool::class)->all())
        ->toEqualCanonicalizing(ToolGroup::core())
        ->and($searches[0]->tools)->toHaveCount(count($tools) - count(ToolGroup::core()));
});

test('a chain reaching a provider without tool search gets plain tools', function (): void {
    config()->set('ai.default', 'openai');
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Gemini->value])->create();

    $tools = [...(new MediaAgent)->tools()];

    expect(resolve(ToolPayload::class)->build($tools))->toBe($tools);
});

test('a streamed chat turn on a mixed chain never sends a ToolSearch wrapper', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');

    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Gemini->value])->create();
    MediaAgent::fake(['ok']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('ai.chat.stream'), ['message' => 'hi'], ['Accept' => 'text/event-stream'])
        ->assertOk()
        ->streamedContent();

    MediaAgent::assertPrompted(fn ($prompt): bool => $prompt->tools !== null
        && collect($prompt->tools)->doesntContain(fn (object $tool): bool => $tool instanceof ToolSearch));
});

test('a streamed chat turn on a tool-search chain defers non-core tools', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');

    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => Lab::Anthropic->value])->create();
    MediaAgent::fake(['ok']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('ai.chat.stream'), ['message' => 'hi'], ['Accept' => 'text/event-stream'])
        ->assertOk()
        ->streamedContent();

    MediaAgent::assertPrompted(fn ($prompt): bool => collect($prompt->tools ?? [])->contains(fn (object $tool): bool => $tool instanceof ToolSearch));
});
