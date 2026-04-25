<?php

declare(strict_types=1);

use App\Ai\Agents\CommandAgent;
use App\Ai\Agents\MediaAdvisorAgent;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('mediamanager.ai.enabled', true);
});

test('returns 404 when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('ai.chat'))->assertNotFound();
});

test('guests are redirected to login', function (): void {
    $this->get(route('ai.chat'))->assertRedirect(route('login'));
});

test('non-admins cannot access AI chat', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('ai.chat'))->assertForbidden();

    $member = User::factory()->member()->create();
    $this->actingAs($member)->get(route('ai.chat'))->assertForbidden();
});

test('admin can view chat page', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('ai.chat'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('AI/Chat')
            ->has('agents', 2)
            ->where('defaultAgent', 'command')
        );
});

test('admin can send a message to CommandAgent', function (): void {
    CommandAgent::fake(['Action queued.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Delete Breaking Bad',
            'agent' => 'command',
        ])
        ->assertOk();

    expect($response->json('text'))->toBe('Action queued.');
    expect($response->json('agent'))->toBe('command');
});

test('admin can send a message to MediaAdvisorAgent', function (): void {
    MediaAdvisorAgent::fake(['You have 3 unwatched series.']);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'What should I watch?',
            'agent' => 'advisor',
        ])
        ->assertOk();

    expect($response->json('text'))->toBe('You have 3 unwatched series.');
    expect($response->json('agent'))->toBe('advisor');
});

test('message is validated as required', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

test('agent value is validated to allowed options', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'hello',
            'agent' => 'not_an_agent',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['agent']);
});

test('exception messages are not leaked to the client outside local env', function (): void {
    Log::spy();
    $this->app['env'] = 'production';
    $this->withoutMiddleware(PreventRequestForgery::class);

    CommandAgent::fake(fn (): never => throw new RuntimeException('LEAKED-PROVIDER-SECRET-https://api.example.com?token=abc'));

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Delete Breaking Bad',
            'agent' => 'command',
        ])
        ->assertStatus(500);

    expect($response->json('error'))->toBe('AI request failed.');
    expect($response->json('message'))->toBeNull();
    expect($response->getContent())->not->toContain('LEAKED-PROVIDER-SECRET');

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'AI request failed.'
            && ($context['user_id'] ?? null) === $admin->id
            && ($context['agent'] ?? null) === 'command'
            && str_contains((string) ($context['message'] ?? ''), 'LEAKED-PROVIDER-SECRET')
    );
});

test('exception messages are surfaced to the client in local env', function (): void {
    $this->app['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);

    CommandAgent::fake(fn (): never => throw new RuntimeException('LOCAL-DEBUG-DETAIL'));

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('ai.chat.send'), [
            'message' => 'Delete Breaking Bad',
            'agent' => 'command',
        ])
        ->assertStatus(500);

    expect($response->json('error'))->toBe('AI request failed.');
    expect($response->json('message'))->toBe('LOCAL-DEBUG-DETAIL');
});
