<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function chatSheetPhoneOpenScript(): string
{
    return confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"sheet-content\"] [data-chat-input]") && document.querySelector("[data-slot=\"sheet-content\"]").getAnimations().length === 0');
}

/**
 * Records the body of every fetch the page sends, so a test can read what a
 * chat turn carried.
 */
function chatSheetPhoneRecordFetchScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmFetchBodies = [];
            const original = window.fetch;
            window.fetch = (input, init) => {
                if (init && typeof init.body === 'string') {
                    window.__mmFetchBodies.push(init.body);
                }
                return original(input, init);
            };
        })()
    JS;
}

test('the header AI button opens the chat as a full-screen sheet on a phone', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertMissing('[data-header-ai-chat]')
        ->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());

    $webpage->assertScript('location.pathname', '/dashboard')
        ->assertScript('Math.round(document.querySelector("[data-slot=\"sheet-content\"]").getBoundingClientRect().width) === window.innerWidth', true)
        ->assertScript(phoneOverflowScript('[data-slot="sheet-content"]'), '')
        ->click('[data-slot="sheet-content"] [data-sheet-close]');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-chat-input]")'));
    $webpage->assertMissing('[data-chat-input]')
        ->assertNoSmoke();
});

test('the phone sidebar AI Assistant entry closes the sidebar and opens the chat sheet', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-sidebar="trigger"]')
        ->click('[data-sidebar-ai-chat]');
    $webpage->script(chatSheetPhoneOpenScript());

    $webpage->assertScript('!!document.querySelector("[data-sidebar=\"sidebar\"][data-mobile=\"true\"]")', false)
        ->assertScript('location.pathname', '/dashboard')
        ->assertNoSmoke();
});

test('the chat header ⋯ holds the mode and model controls on a phone, and the mode reaches the turn', function (): void {
    MediaAgent::fake(['Sure, here is what I found.']);
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());

    $webpage->assertMissing('[data-chat-model-chip]')
        ->click('[data-chat-options]')
        ->assertVisible('[data-chat-options-panel]')
        ->assertAttribute('[data-chat-menu-mode="executive"]', 'aria-pressed', 'true')
        ->click('[data-chat-menu-mode="advisory"]')
        ->assertAttribute('[data-chat-menu-mode="advisory"]', 'aria-pressed', 'true')
        ->assertVisible('[data-chat-options-panel] [data-chat-model-options]')
        ->assertScript(phoneOverflowScript('[data-chat-options-panel]'), '');
    $webpage->keys('[data-chat-options-panel]', 'Escape');

    $webpage->script(chatSheetPhoneRecordFetchScript());
    $webpage->type('[data-slot="sheet-content"] [data-chat-input]', 'What is on my watchlist?')
        ->keys('[data-slot="sheet-content"] [data-chat-input]', 'Enter')
        ->assertSee('Sure, here is what I found.');

    $webpage->assertScript('window.__mmFetchBodies.some((body) => body.includes("\"mode\":\"advisory\""))', true)
        ->assertNoSmoke();
});

test('the chat sheet follows the visual viewport so the composer stays above the keyboard', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());

    // The soft keyboard shrinks the visual viewport, not the layout viewport.
    $webpage->script('(() => { Object.defineProperty(window.visualViewport, "height", { configurable: true, get: () => 400 }); window.visualViewport.dispatchEvent(new Event("resize")); })()');
    $webpage->script(confirmDialogWaitUntilScript('Math.round(document.querySelector("[data-slot=\"sheet-content\"]").getBoundingClientRect().height) === 400'));

    $webpage->assertScript('Math.round(document.querySelector("[data-slot=\"sheet-content\"]").getBoundingClientRect().height)', 400)
        ->assertScript('document.querySelector("[data-slot=\"sheet-content\"] [data-chat-input]").getBoundingClientRect().bottom <= 400', true);
});

test('the chat page fits a phone and keeps its controls in the ⋯ menu', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('ai.chat', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertVisible('[data-chat-options]')
        ->assertMissing('[data-chat-mode="advisory"]')
        ->assertScript(phoneOverflowScript('main'), '')
        ->assertScript('document.querySelector("[data-chat-input]").getBoundingClientRect().bottom <= window.innerHeight', true);
});

test('a conversation with a long title truncates in the phone sheet header', function (): void {
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'title' => 'An Extremely Long Example Conversation Title That Would Push The Header Past The Screen',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($admin);

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());

    $webpage->click('[data-slot="sheet-content"] [data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]");
    $webpage->script(confirmDialogWaitUntilScript('(document.querySelector("[data-slot=\"sheet-content\"] [data-conversation-picker]")?.textContent ?? "").includes("An Extremely Long Example")'));

    $webpage->assertScript(phoneOverflowScript('[data-slot="sheet-content"]'), '')
        ->assertScript('(() => { const picker = document.querySelector("[data-slot=\"sheet-content\"] [data-conversation-picker]").getBoundingClientRect(); const options = document.querySelector("[data-slot=\"sheet-content\"] [data-chat-options]").getBoundingClientRect(); const close = document.querySelector("[data-slot=\"sheet-content\"] [data-sheet-close]").getBoundingClientRect(); return picker.right <= options.left && options.right <= close.left; })()', true)
        ->assertNoSmoke();

    // Renaming starts from the picker's menu on a phone; the rename row must
    // leave the ⋯ and the sheet's close X where they were, not overlap them.
    $webpage->click('[data-slot="sheet-content"] [data-conversation-picker]')
        ->click('Rename current')
        ->assertVisible('[data-slot="sheet-content"] [data-chat-rename-input]')
        ->assertScript(phoneOverflowScript('[data-slot="sheet-content"]'), '')
        ->assertScript('(() => { const rect = (selector) => document.querySelector("[data-slot=\"sheet-content\"] " + selector).getBoundingClientRect(); const options = rect("[data-chat-options]"); return rect("[data-chat-rename-save]").right <= options.left && rect("[aria-label=\"Cancel rename\"]").right <= options.left && options.right <= rect("[data-sheet-close]").left; })()', true);
});

test('at desktop width the header links to the chat page and the sheet keeps its model chip', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->assertAttribute('[data-header-ai-chat]', 'href', '/ai/chat')
        ->assertMissing('[data-header-ai-chat-sheet]')
        ->click('AI Assistant');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"sheet-content\"] [data-chat-input]")'));
    $webpage->assertVisible('[data-chat-model-chip]')
        ->assertMissing('[data-chat-options]');
});
