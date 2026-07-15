<?php

declare(strict_types=1);

use App\Models\User;

test('AI admin nav links are hidden when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Connections')
        ->assertDontSee('AI Settings')
        ->assertDontSee('Decision Agent')
        ->assertDontSee('AI Usage')
        ->assertDontSee('AI Conversations')
        ->assertDontSee('AI Prices')
        ->assertDontSee('AI Assistant');
});

test('AI admin nav links are visible when AI is enabled', function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('AI Settings')
        ->assertSee('Decision Agent')
        ->assertSee('AI Usage')
        ->assertSee('AI Conversations')
        ->assertSee('AI Prices');
});
