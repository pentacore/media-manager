<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Routing\ChatToolRouter;
use App\Ai\Routing\ToolGroup;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\Data\BooleanAnswer;

/**
 * @param  array<string, float>  $probabilities
 * @return array<string, BooleanAnswer>
 */
function routerGroupAnswers(array $probabilities): array
{
    return collect(ToolGroup::cases())
        ->mapWithKeys(fn (ToolGroup $toolGroup): array => [$toolGroup->value => new BooleanAnswer($probabilities[$toolGroup->value] ?? 0.05)])
        ->all();
}

/**
 * Class names of the tools a prompt carried, with any ToolSearch wrapper
 * expanded so the assertions hold whether or not the chain defers tools.
 *
 * @return list<class-string>
 */
function routerPromptedToolClasses(AgentPrompt $agentPrompt): array
{
    return collect($agentPrompt->tools ?? [])
        ->flatMap(fn (object $tool): array => $tool instanceof ToolSearch ? $tool->tools : [$tool])
        ->map(fn (object $tool): string => $tool::class)
        ->values()
        ->all();
}

beforeEach(function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(true);
});

test('confident groups are routed and filtering keeps core tools', function (): void {
    Classification::fake([routerGroupAnswers(['playback' => 0.9])]);
    $chatToolRouter = resolve(ChatToolRouter::class);

    $groups = $chatToolRouter->route('What is playing right now?', null);
    $filtered = collect($chatToolRouter->filter([...(new MediaAgent)->tools()], $groups))->map(fn (object $tool): string => $tool::class);

    expect($groups)->toBe([ToolGroup::Playback])
        ->and($filtered)->toContain(NowPlayingTool::class, SearchMediaTool::class)
        ->and($filtered)->not->toContain(DeleteMediaTool::class);
});

test('low confidence everywhere means the full toolset', function (): void {
    Classification::fake([routerGroupAnswers([])]);

    expect(resolve(ChatToolRouter::class)->route('hmm', null))->toBeNull();
});

test('routing disabled or failing means the full toolset', function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(false);
    expect(resolve(ChatToolRouter::class)->route('delete Dune', null))->toBeNull();

    resolve(AiSettings::class)->setChatRoutingEnabled(true);
    Classification::fake(fn () => throw new RuntimeException('down'));
    expect(resolve(ChatToolRouter::class)->route('delete Dune', null))->toBeNull();
});

test('groups of tools called in the previous turn stay loaded', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'agent' => MediaAgent::class,
        'role' => 'assistant',
        'content' => 'Shall I delete Dune?',
        'attachments' => '[]',
        'steps' => json_encode([[
            'content' => 'Shall I delete Dune?',
            'tool_calls' => [['id' => 'c1', 'name' => 'DeleteMediaTool', 'arguments' => [], 'result' => '{}']],
            'reasoning' => '',
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]]),
        'usage' => '{}',
        'meta' => '{}',
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Classification::fake([routerGroupAnswers(['playback' => 0.6])]);

    expect(resolve(ChatToolRouter::class)->route('yes do it', $conversationId))
        ->toContain(ToolGroup::LibraryChanges, ToolGroup::Playback);
});

test('a routed chat turn only sends the routed tools to the agent', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Classification::fake([routerGroupAnswers(['playback' => 0.9])]);
    MediaAgent::fake(['ok']);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('ai.chat.send'), ['message' => 'What is playing right now?'])
        ->assertOk();

    MediaAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => in_array(NowPlayingTool::class, routerPromptedToolClasses($agentPrompt), true)
        && ! in_array(DeleteMediaTool::class, routerPromptedToolClasses($agentPrompt), true));
});

test('an approved workflow continuation keeps the full toolset without classifying', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Classification::fake([routerGroupAnswers(['playback' => 0.9])]);
    MediaAgent::fake(['Executing now.']);
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $workflow = AiProposedWorkflow::factory()->create([
        'user_id' => $admin->id,
        'conversation_id' => $conversationId,
        'status' => AiProposedWorkflowStatus::Proposed,
    ]);

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'I approve the proposed workflow.',
            'conversation_id' => $conversationId,
            'workflow_id' => $workflow->id,
            'workflow_action' => 'approved',
        ])
        ->assertOk();

    Classification::assertNothingClassified();
    MediaAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => in_array(DeleteMediaTool::class, routerPromptedToolClasses($agentPrompt), true));
});
