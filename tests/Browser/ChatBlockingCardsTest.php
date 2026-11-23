<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiProposedWorkflowStatus;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiProposedWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;

/*
 * A workflow approval runs its turn on the blocking send() path, not the
 * stream: the reply's cards come back in the JSON body. These tests answer
 * an approval with an OfferChoices call and check the card that path draws.
 */

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Bus::fake([GenerateConversationTitle::class]);
});

/**
 * A proposed two-step workflow owned by $user, as ProposeWorkflowTool stores it.
 */
function chatBlockingCardsProposeWorkflow(User $user): AiProposedWorkflow
{
    return AiProposedWorkflow::create([
        'id' => (string) Str::uuid7(),
        'user_id' => $user->id,
        'conversation_id' => null,
        'rationale' => 'Clearing two stuck imports',
        'steps' => [
            ['action' => 'manual_import', 'target' => 'Example Show S01E01', 'reason' => 'Stuck on a sample file'],
            ['action' => 'manual_import', 'target' => 'Example Show S01E02', 'reason' => 'Stuck on a sample file'],
        ],
        'status' => AiProposedWorkflowStatus::Proposed,
    ]);
}

/**
 * MediaAgent proposes the workflow on the first turn, answers the approval
 * with an OfferChoices call built from $choices, then ends each later turn
 * with $finalReply.
 *
 * @param  array<string, mixed>  $choices
 */
function chatBlockingCardsFakeAgent(User $user, array $choices, string $finalReply): void
{
    $calls = 0;
    MediaAgent::fake(function () use ($user, $choices, $finalReply, &$calls): ToolCall|string {
        $calls++;

        return match ($calls) {
            1 => (function () use ($user): string {
                chatBlockingCardsProposeWorkflow($user);

                return 'I have proposed a 2-step workflow.';
            })(),
            2 => new ToolCall(id: 'call_blocking_choices', name: 'OfferChoicesTool', arguments: $choices),
            3 => 'Before I run it, pick below.',
            default => $finalReply,
        };
    });
}

/**
 * The description shown beside a multi-select option's checkbox (its
 * `<label>` row), or '' when that option has none.
 */
function chatBlockingCardsToggleDescriptionScript(string $label): string
{
    $selector = json_encode(sprintf('[data-choice-toggle="%s"]', $label), JSON_THROW_ON_ERROR);

    return "document.querySelector({$selector})?.closest('label')?.querySelector('[data-choice-description]')?.textContent.trim() ?? ''";
}

test('a workflow approval answered with a choices card renders it from the blocking reply', function (): void {
    $admin = User::factory()->admin()->create();
    chatBlockingCardsFakeAgent($admin, [
        'question' => 'Which episode should I import first?',
        'options' => [['label' => 'S01E01 first'], ['label' => 'S01E02 first', 'description' => 'The newer episode']],
    ], 'Importing S01E02 first.');
    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->type('[data-chat-input]', 'Two imports are stuck')
        ->click('Send')
        ->assertSee('Proposed workflow')
        ->click('Approve')
        ->assertSeeIn('[data-chat-thread]', 'Before I run it, pick below.')
        ->assertSeeIn('[data-chat-thread] [data-choice-card]', 'Which episode should I import first?')
        ->assertSeeIn('[data-choice-option="S01E02 first"] [data-choice-description]', 'The newer episode')
        ->assertMissing('[data-choice-option="S01E01 first"] [data-choice-description]')
        ->click('[data-choice-option="S01E02 first"]')
        ->assertSeeIn('[data-chat-thread]', 'Importing S01E02 first.')
        ->assertDisabled('[data-choice-option="S01E01 first"]')
        ->assertVisible('[data-choice-option="S01E02 first"][data-choice-chosen]')
        ->assertNoJavaScriptErrors();

    expect(AiProposedWorkflow::query()->sole()->status)->toBe(AiProposedWorkflowStatus::Approved)
        ->and(DB::table('agent_conversation_messages')->where('role', 'user')->where('content', 'S01E02 first')->exists())->toBeTrue();
});

test('a multi-select card from the blocking reply shows each description and sends the ticked labels', function (): void {
    $admin = User::factory()->admin()->create();
    chatBlockingCardsFakeAgent($admin, [
        'question' => 'Which files should I import?',
        'multiple' => true,
        'options' => [
            ['label' => 'Episode file', 'description' => 'Example.Show.S01E01.1080p.mkv, 2.1 GB'],
            ['label' => 'Sample file', 'description' => 'sample.mkv, 40 MB'],
            ['label' => 'Subtitle file'],
        ],
    ], 'Importing the episode and subtitle files.');
    $this->actingAs($admin);

    visit(route('ai.chat', absolute: false))
        ->assertNoSmoke()
        ->type('[data-chat-input]', 'Two imports are stuck')
        ->click('Send')
        ->assertSee('Proposed workflow')
        ->click('Approve')
        ->assertSeeIn('[data-chat-thread] [data-choice-card]', 'Which files should I import?')
        ->assertScript(chatBlockingCardsToggleDescriptionScript('Episode file'), 'Example.Show.S01E01.1080p.mkv, 2.1 GB')
        ->assertScript(chatBlockingCardsToggleDescriptionScript('Sample file'), 'sample.mkv, 40 MB')
        ->assertScript(chatBlockingCardsToggleDescriptionScript('Subtitle file'), '')
        ->assertDisabled('[data-choice-confirm]')
        ->click('[data-choice-toggle="Subtitle file"]')
        ->click('[data-choice-toggle="Episode file"]')
        ->click('[data-choice-confirm]')
        ->assertSeeIn('[data-chat-thread]', 'Importing the episode and subtitle files.')
        ->assertVisible('[data-choice-toggle="Episode file"][data-choice-chosen]')
        ->assertVisible('[data-choice-toggle="Subtitle file"][data-choice-chosen]')
        ->assertMissing('[data-choice-toggle="Sample file"][data-choice-chosen]')
        ->assertNoJavaScriptErrors();

    expect(DB::table('agent_conversation_messages')->where('role', 'user')->where('content', 'Episode file, Subtitle file')->exists())->toBeTrue();
});
