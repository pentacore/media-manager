<?php

declare(strict_types=1);

use App\Models\User;

test('the AI admin sub-group is hidden when AI is disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        // Scoped positive anchor: assertDontSeeIn() asserts count() === 0, which
        // passes vacuously if `[data-sidebar="content"]` ever stops matching, so
        // the anchor has to be scoped to the same selector to be worth anything.
        ->assertSeeIn('[data-sidebar="content"]', 'Configuration')
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
        ->assertSeeIn('[data-sidebar="content"]', 'AI Settings')
        ->assertSeeIn('[data-sidebar="content"]', 'Decision Agent')
        ->assertSeeIn('[data-sidebar="content"]', 'AI Usage')
        ->assertSeeIn('[data-sidebar="content"]', 'AI Conversations')
        ->assertSeeIn('[data-sidebar="content"]', 'AI Prices');
});

// AppSidebar's footer affordance is a two-branch conditional: a ⌘J
// SidebarMenuButton when not mobile, an `AI Assistant` <Link> when mobile.
// Nothing covered either branch — the only other `AI Assistant` assertions in
// the suite are the AI-disabled negative above and a page heading in
// AiChatTest — so deleting the v-else left mobile admins with no route to the
// assistant at all (⌘J is unreachable on touch, which is the branch's entire
// justification) and the suite stayed green.
//
// Both branches are asserted structurally as well as by text, because the text
// is identical on either side: the discriminator is button-vs-anchor plus the
// presence of the keyboard hint. The counts also make the assertions
// non-vacuous in the direction that matters — `assertCount(…, 0)` on the wrong
// branch's selector would still pass if the footer vanished, so each test
// pairs it with a positive scoped to the same `[data-sidebar="footer"]` root,
// which resolves to exactly one element at both widths (verified).
test('an AI-enabled admin gets the keyboard affordance on desktop', function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="footer"]', 'AI Assistant')
        ->assertSeeIn('[data-sidebar="footer"]', '⌘J')
        ->assertCount('[data-sidebar="footer"] button:has-text("AI Assistant")', 1)
        // The mobile branch must not also render, or the shortcut hint would be
        // duplicated onto a link that does not respond to it.
        ->assertCount('[data-sidebar="footer"] a:has-text("AI Assistant")', 0);
});

test('an AI-enabled admin gets a navigable AI Assistant link on mobile', function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());

    // The sidebar renders a mobile Sheet XOR the desktop aside, so the trigger
    // has to be clicked after the resize before `[data-sidebar="footer"]`
    // exists inside the Sheet.
    visit('/dashboard')
        ->resize(390, 844)
        ->assertNoSmoke()
        ->click('[data-sidebar="trigger"]')
        ->assertSeeIn('[data-sidebar="footer"]', 'AI Assistant')
        ->assertCount('[data-sidebar="footer"] a:has-text("AI Assistant")', 1)
        // Asserting the href rather than clicking keeps the test on the nav
        // contract: what matters is that touch users have a reachable route to
        // the assistant, which the ⌘J button alone does not give them.
        ->assertAttribute('[data-sidebar="footer"] a:has-text("AI Assistant")', 'href', '/ai/chat')
        // No ⌘J hint on mobile: there is no keyboard to press it with, and the
        // absent <kbd> is what distinguishes this branch from the desktop one.
        ->assertCount('[data-sidebar="footer"] kbd', 0);
});
