<?php

declare(strict_types=1);

use App\Models\User;

test('the AI admin sub-group is hidden when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Configuration')
        ->assertDontSeeIn('[data-sidebar="content"]', 'AI Settings')
        ->assertDontSeeIn('[data-sidebar="content"]', 'Decision Agent')
        ->assertDontSeeIn('[data-sidebar="content"]', 'AI Usage')
        ->assertDontSeeIn('[data-sidebar="content"]', 'AI Conversations')
        ->assertDontSeeIn('[data-sidebar="content"]', 'AI Prices')
        // Not scoped to the nav container: the AI Assistant affordance lives in
        // the sidebar footer, so a `[data-sidebar="content"]` scope would make
        // this assertion vacuous.
        ->assertDontSee('AI Assistant');
});

test('the AI admin sub-group holds every AI page when AI is enabled', function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertDontSeeIn('[data-sidebar="content"]', 'AI Settings')
        // Scoped rather than exact-matched. A bare `button:has-text("AI")` also
        // matches the footer's `AI Assistant`, and `button:text-is("AI")` never
        // matches at all: Playwright's text engine drops an element when a
        // child matches the same text, so the trigger's inner `<span>AI</span>`
        // shadows the button. `[data-sidebar="content"]` excludes the footer.
        ->click('[data-sidebar="content"] button:has-text("AI")')
        ->assertSee('AI Settings')
        ->assertSee('Decision Agent')
        ->assertSee('AI Usage')
        ->assertSee('AI Conversations')
        ->assertSee('AI Prices');
});
