<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Events\Ai\AgentStepUpdate;
use App\Listeners\Ai\BroadcastAgentStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Events\ToolInvoked;

test('listener broadcasts AgentStepUpdate when conversation context is present', function (): void {
    Event::fake([AgentStepUpdate::class]);

    $user = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->id,
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mediaAgent = (new MediaAgent)->continue($conversationId, as: $user);

    (new BroadcastAgentStep)->handle(new ToolInvoked(
        invocationId: 'inv-1',
        toolInvocationId: 'tic-1',
        agent: $mediaAgent,
        tool: resolve(GetServiceStatusTool::class),
        arguments: [],
        result: ['ok' => true],
    ));

    Event::assertDispatched(fn (AgentStepUpdate $agentStepUpdate): bool => $agentStepUpdate->userId === $user->id
        && $agentStepUpdate->conversationId === $conversationId
        && $agentStepUpdate->toolName === 'GetServiceStatusTool'
        && $agentStepUpdate->status === AgentStepUpdate::STATUS_FINISHED);
});

test('listener silently skips when agent has no conversation context', function (): void {
    Event::fake([AgentStepUpdate::class]);

    $agent = new MediaAgent; // never attached to a conversation

    (new BroadcastAgentStep)->handle(new ToolInvoked(
        invocationId: 'inv-2',
        toolInvocationId: 'tic-2',
        agent: $agent,
        tool: resolve(GetServiceStatusTool::class),
        arguments: [],
        result: ['ok' => true],
    ));

    Event::assertNotDispatched(AgentStepUpdate::class);
});

test('AgentStepUpdate broadcasts on per-conversation private channel', function (): void {
    $event = new AgentStepUpdate(
        userId: 42,
        conversationId: 'a1b2c3',
        toolName: 'SonarrSearchSeriesTool',
        status: AgentStepUpdate::STATUS_FINISHED,
    );

    expect($event->broadcastOn()->name)->toBe('private-ai-chat.42.a1b2c3');
    expect($event->broadcastAs())->toBe('AgentStepUpdate');
    expect($event->broadcastWith())->toMatchArray([
        'conversation_id' => 'a1b2c3',
        'tool_name' => 'SonarrSearchSeriesTool',
        'status' => 'finished',
    ]);
});
