<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    resolve(BroadcastManager::class)->purge();

    require base_path('routes/channels.php');
});

test('the chat liveness channel authorises the conversation owner only', function (): void {
    $owner = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert(['id' => $conversationId, 'participant_type' => $owner->getMorphClass(), 'participant_id' => $owner->id, 'title' => 'T', 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['channel_name' => sprintf('private-ai-chat.%d.%s', $owner->id, $conversationId), 'socket_id' => '1234.1234567'])
        ->assertOk();

    $this->actingAs($other)
        ->post('/broadcasting/auth', ['channel_name' => sprintf('private-ai-chat.%d.%s', $other->id, $conversationId), 'socket_id' => '1234.1234567'])
        ->assertForbidden();
});
