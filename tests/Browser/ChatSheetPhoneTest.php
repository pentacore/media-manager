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

/**
 * Holds every PATCH the page sends until the test releases it, so a test can
 * see the chat while a model change is still being saved.
 */
function chatSheetPhoneHoldPatchScript(): string
{
    return <<<'JS'
        (() => {
            window.__mmHeldPatches = [];
            const original = window.fetch;
            window.fetch = (input, init) => {
                if (init && init.method === 'PATCH') {
                    return new Promise((resolve, reject) => {
                        window.__mmHeldPatches.push(() => original(input, init).then(resolve, reject));
                    });
                }
                return original(input, init);
            };
        })()
    JS;
}

/**
 * The chat's default model and a second one to pick, as ChatModelOverrideTest
 * sets them up.
 */
function chatSheetPhoneSeedModels(): void
{
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-nano']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->reasoning(AiReasoningLevel::Medium)->create();
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
        // A phone has no ⌘J and no resize handle, so the description names neither.
        ->assertScript('document.querySelector("[data-slot=\"sheet-content\"] [data-slot=\"sheet-description\"]").textContent.trim()', 'Chat with MediaAgent.')
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
        ->assertScript('location.pathname', '/dashboard');

    // The sidebar's own button is gone once the chat closes, so focus lands
    // on the sidebar trigger instead of dropping to <body>.
    $webpage->click('[data-slot="sheet-content"] [data-sheet-close]');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-chat-input]") && document.activeElement !== document.body'));
    $webpage->assertScript('document.activeElement.matches("[data-sidebar=\"trigger\"]")', true)
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

    // iOS also pans the visual viewport down to the focused composer. The
    // sheet follows it, and its close X must move with the sheet.
    $webpage->script('(() => { Object.defineProperty(window.visualViewport, "offsetTop", { configurable: true, get: () => 300 }); window.visualViewport.dispatchEvent(new Event("resize")); })()');
    $webpage->script(confirmDialogWaitUntilScript('Math.round(document.querySelector("[data-slot=\"sheet-content\"]").getBoundingClientRect().top) === 300'));

    $webpage->assertScript('(() => { const sheet = document.querySelector("[data-slot=\"sheet-content\"]").getBoundingClientRect(); const close = document.querySelector("[data-slot=\"sheet-content\"] [data-sheet-close]").getBoundingClientRect(); return close.top >= sheet.top && close.bottom <= sheet.bottom && close.left >= sheet.left && close.right <= sheet.right && close.right <= window.innerWidth; })()', true)
        ->assertScript('document.querySelector("[data-slot=\"sheet-content\"] [data-chat-input]").getBoundingClientRect().bottom <= 700', true);
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

test('on the chat page the phone entries never stack a second chat over it', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('ai.chat', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $webpage->assertMissing('[data-header-ai-chat-sheet]')
        ->click('[data-sidebar="trigger"]')
        ->click('[data-sidebar-ai-chat]');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-sidebar=\"sidebar\"][data-mobile=\"true\"]")'));

    $webpage->assertCount('[data-chat-input]', 1)
        ->assertMissing('[data-slot="sheet-content"] [data-chat-input]')
        ->assertScript('location.pathname', '/ai/chat')
        ->assertNoSmoke();
});

test('a model picked in the phone ⋯ panel is saved, and the panel locks while it saves', function (): void {
    chatSheetPhoneSeedModels();
    $admin = User::factory()->admin()->create();
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'title' => 'Example model chat',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($admin);

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());
    $webpage->click('[data-slot="sheet-content"] [data-conversation-picker]')
        ->click("[data-conversation-id=\"{$conversationId}\"]")
        // The closing menu hands focus back to its trigger, which would dismiss a popover opened before it unmounts.
        ->assertMissing('[data-slot="dropdown-menu-content"]');
    $webpage->script(confirmDialogWaitUntilScript('(document.querySelector("[data-slot=\"sheet-content\"] [data-conversation-picker]")?.textContent ?? "").includes("Example model chat")'));

    $webpage->script(chatSheetPhoneHoldPatchScript());
    $webpage->click('[data-chat-options]');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelector("[data-chat-options-panel] [data-chat-model-select] button")?.disabled === false'));
    $webpage->click('[data-chat-options-panel] [data-chat-model-select] button')
        ->click('[role="option"][aria-label="gpt-5-nano"]');

    // The PATCH is held, so the override is still saving: the panel's controls are locked.
    $webpage->script(confirmDialogWaitUntilScript('window.__mmHeldPatches.length === 1'));
    $webpage->assertScript('document.querySelector("[data-chat-options-panel] [data-chat-model-select] button").disabled', true)
        ->assertScript('document.querySelector("[data-chat-options-panel] [data-chat-model-reset]").disabled', true);

    $webpage->script('window.__mmHeldPatches.forEach((release) => release())');
    $webpage->script(confirmDialogWaitUntilScript('document.querySelector("[data-chat-options-panel] [data-chat-model-reset]")?.disabled === false'));
    $webpage->assertSeeIn('[data-chat-options-panel] [data-chat-model-select]', 'gpt-5-nano')
        ->assertNoSmoke();

    expect(DB::table('agent_conversations')->where('id', $conversationId)->value('model'))->toBe('gpt-5-nano');
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
        ->click('[data-conversation-rename]')
        ->assertVisible('[data-slot="sheet-content"] [data-chat-rename-input]')
        ->assertScript(phoneOverflowScript('[data-slot="sheet-content"]'), '')
        ->assertScript('(() => { const rect = (selector) => document.querySelector("[data-slot=\"sheet-content\"] " + selector).getBoundingClientRect(); const options = rect("[data-chat-options]"); return rect("[data-chat-rename-save]").right <= options.left && rect("[data-chat-rename-cancel]").right <= options.left && options.right <= rect("[data-sheet-close]").left; })()', true)
        // Two 28 px targets need 12 px between them, or Cancel's 40 px hit
        // area takes taps inside Save's.
        ->assertScript('(() => { const rect = (selector) => document.querySelector("[data-slot=\\"sheet-content\\"] " + selector).getBoundingClientRect(); return rect("[data-chat-rename-cancel]").left - rect("[data-chat-rename-save]").right >= 12; })()', true);
});

test('at desktop width the header links to the chat page and the sheet keeps its model chip', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertAttribute('[data-header-ai-chat]', 'href', '/ai/chat')
        ->assertMissing('[data-header-ai-chat-sheet]')
        ->click('[data-sidebar-ai-chat-open]');
    $webpage->script(confirmDialogWaitUntilScript('!!document.querySelector("[data-slot=\"sheet-content\"] [data-chat-input]")'));
    $webpage->assertVisible('[data-chat-model-chip]')
        ->assertMissing('[data-chat-options]');
});

test('the phone chat sheet keeps the chosen mode when it is reopened', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());
    $webpage->click('[data-chat-options]')
        ->click('[data-chat-menu-mode="advisory"]')
        ->assertAttribute('[data-chat-menu-mode="advisory"]', 'aria-pressed', 'true');
    $webpage->keys('[data-chat-options-panel]', 'Escape');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-chat-options-panel]")'));

    $webpage->click('[data-slot="sheet-content"] [data-sheet-close]');
    $webpage->script(confirmDialogWaitUntilScript('!document.querySelector("[data-chat-input]")'));
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());

    $webpage->click('[data-chat-options]')
        ->assertAttribute('[data-chat-menu-mode="advisory"]', 'aria-pressed', 'true')
        ->assertAttribute('[data-chat-menu-mode="executive"]', 'aria-pressed', 'false')
        ->assertNoSmoke();
});

test('in phone landscape the chat sheet still follows the visual viewport', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());

    // A phone turned to landscape: at least sm wide, still a touch screen.
    $webpage->resize(812, 375);
    $webpage->assertScript('matchMedia("(pointer: coarse)").matches && innerWidth >= 640', true);
    $webpage->script('(() => { Object.defineProperty(window.visualViewport, "height", { configurable: true, get: () => 200 }); window.visualViewport.dispatchEvent(new Event("resize")); })()');
    $webpage->script(confirmDialogWaitUntilScript('Math.round(document.querySelector("[data-slot=\\"sheet-content\\"]").getBoundingClientRect().height) === 200'));

    $webpage->assertScript('Math.round(document.querySelector("[data-slot=\\"sheet-content\\"]").getBoundingClientRect().height)', 200)
        ->assertScript('document.querySelector("[data-slot=\\"sheet-content\\"] [data-chat-input]").getBoundingClientRect().bottom <= 200', true);
});

test('pinned chat templates are 40 px targets in the phone chat sheet', function (): void {
    $admin = User::factory()->admin()->create();
    $first = ChatTemplate::factory()->for($admin)->pinned()->create(['name' => 'Stuck downloads']);
    $second = ChatTemplate::factory()->for($admin)->pinned()->create(['name' => 'Missing subtitles']);
    $this->actingAs($admin);

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->click('[data-header-ai-chat-sheet]');
    $webpage->script(chatSheetPhoneOpenScript());
    $webpage->script(confirmDialogWaitUntilScript(sprintf('!!document.querySelector("[data-template-chip=\\"%d\\"]")', $second->id)));

    foreach ([$first, $second] as $chatTemplate) {
        $chip = sprintf('document.querySelector("[data-template-chip=\\"%d\\"]")', $chatTemplate->id);
        $webpage->assertScript(sprintf('(() => { const chip = %s; const rect = chip.getBoundingClientRect(); const hits = (y) => chip.contains(document.elementFromPoint(rect.left + rect.width / 2, y)); return rect.height >= 40 && hits(rect.top + 1) && hits(rect.bottom - 1); })()', $chip), true);
    }

    $webpage->click(sprintf('[data-template-chip="%d"]', $first->id))
        ->assertScript('document.querySelector("[data-slot=\\"sheet-content\\"] [data-chat-input]").value.includes("stuck downloads")', true)
        ->assertNoSmoke();
});

test("a member's or viewer's phone has neither AI chat entry", function (string $role): void {
    $this->actingAs($role === 'member' ? User::factory()->member()->create() : User::factory()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertVisible('[data-sidebar="trigger"]')
        ->assertCount('[data-header-ai-chat-sheet]', 0)
        ->assertCount('[data-header-ai-chat]', 0)
        ->click('[data-sidebar="trigger"]')
        ->assertPresent('[data-sidebar="sidebar"][data-mobile="true"]')
        ->assertCount('[data-sidebar-ai-chat]', 0)
        ->assertCount('[data-sidebar-ai-chat-link]', 0)
        ->assertNoSmoke();
})->with(['member', 'viewer']);

test('between sm and the sidebar breakpoint both AI entries open the chat page', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->on()->mobile()->assertNoSmoke();
    $webpage->resize(700, 900);
    $webpage->script(appHydratedScript());
    $webpage->assertVisible('[data-header-ai-chat]')
        ->assertAttribute('[data-header-ai-chat]', 'href', '/ai/chat')
        ->assertMissing('[data-header-ai-chat-sheet]')
        ->click('[data-sidebar="trigger"]')
        ->assertPresent('[data-sidebar="sidebar"][data-mobile="true"]')
        ->assertMissing('[data-sidebar-ai-chat]')
        ->assertAttribute('[data-sidebar-ai-chat-link]', 'href', '/ai/chat')
        ->click('[data-sidebar-ai-chat-link]');
    $webpage->script(confirmDialogWaitUntilScript('location.pathname === "/ai/chat"'));

    $webpage->assertScript('location.pathname', '/ai/chat')
        ->assertNoSmoke();
});
