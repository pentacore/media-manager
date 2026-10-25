<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\BenchmarkConversationMode;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $input
 */
function attachmentChatCase(array $input = []): BenchmarkCase
{
    return BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'What does this error mean?',
        'history' => [
            ['role' => 'user', 'content' => 'My download is stuck.'],
            ['role' => 'assistant', 'content' => 'Send me a screenshot.'],
            ['role' => 'user', 'content' => 'Here is the log.'],
        ],
        ...$input,
    ]]);
}

test('a case attachment is stored in its case folder', function (): void {
    $benchmarkCase = attachmentChatCase();
    $attachment = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();

    expect($attachment->path)->toStartWith(sprintf('benchmark-cases/%d/', $benchmarkCase->id))
        ->and($attachment->disk)->toBe('local')
        ->and($attachment->turn)->toBeNull();
    Storage::disk('local')->assertExists($attachment->path);
});

test('deleting a case deletes its attachments and its folder but no other case folder', function (): void {
    $benchmarkCase = attachmentChatCase();
    $attachment = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    $otherAttachment = BenchmarkCaseAttachment::factory()->for(attachmentChatCase())->image()->create();

    $benchmarkCase->delete();

    expect(BenchmarkCaseAttachment::query()->pluck('id')->all())->toBe([$otherAttachment->id]);
    Storage::disk('local')->assertMissing($attachment->path);
    Storage::disk('local')->assertMissing(BenchmarkCaseAttachment::directoryFor($benchmarkCase->id));
    Storage::disk('local')->assertExists($otherAttachment->path);
});

test('attachments are picked by exact turn, so the first history turn never counts as the prompt', function (): void {
    $benchmarkCase = attachmentChatCase();
    $first = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->forTurn(0)->create();
    $final = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();

    expect($benchmarkCase->attachmentsFor(null)->modelKeys())->toBe([$final->id])
        ->and($benchmarkCase->attachmentsFor(0)->modelKeys())->toBe([$first->id])
        ->and($benchmarkCase->attachmentsFor(2)->all())->toBe([]);
});

test('an image becomes a named stored image and anything else a named stored document', function (): void {
    $benchmarkCase = attachmentChatCase();
    $image = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    $text = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->create();

    expect($image->toSdkFile())->toBeInstanceOf(StoredImage::class)
        ->and($image->toSdkFile()->name())->toBe('screenshot.png')
        ->and($text->toSdkFile())->toBeInstanceOf(StoredDocument::class)
        ->and($text->toSdkFile()->name())->toBe('download.log');
});

test('turn keys and labels name the prompt and count history turns', function (): void {
    expect(BenchmarkCaseAttachment::turnFromKey('final'))->toBeNull()
        ->and(BenchmarkCaseAttachment::turnFromKey('2'))->toBe(2)
        ->and(BenchmarkCaseAttachment::turnFromKey(0))->toBe(0)
        ->and(BenchmarkCaseAttachment::keyForTurn(null))->toBe('final')
        ->and(BenchmarkCaseAttachment::keyForTurn(3))->toBe('3')
        ->and(fn (): ?int => BenchmarkCaseAttachment::turnFromKey('later'))->toThrow(InvalidArgumentException::class)
        ->and(BenchmarkCaseAttachment::factory()->make(['turn' => 0])->turnLabel())->toBe('turn 1')
        ->and(BenchmarkCaseAttachment::factory()->make(['turn' => null])->turnLabel())->toBe('final prompt');
});

test('a chat case lists its user turns in order with the prompt last', function (): void {
    expect(attachmentChatCase()->userTurns())->toBe([
        ['turn' => 0, 'content' => 'My download is stuck.'],
        ['turn' => 2, 'content' => 'Here is the log.'],
        ['turn' => null, 'content' => 'What does this error mean?'],
    ]);
});

test('only a chat case set to scripted is scripted', function (AiTask $aiTask, ?string $conversation, bool $scripted): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask($aiTask)->create(['input' => [
        'prompt' => 'p',
        ...($conversation === null ? [] : ['conversation' => $conversation]),
    ]]);

    expect($benchmarkCase->isScripted())->toBe($scripted)
        ->and($benchmarkCase->conversationMode())->toBe($scripted ? BenchmarkConversationMode::Scripted : BenchmarkConversationMode::Context);
})->with([
    'scripted chat' => [AiTask::Chat, 'scripted', true],
    'context chat' => [AiTask::Chat, 'context', false],
    'chat without a replay mode' => [AiTask::Chat, null, false],
    'scripted title' => [AiTask::Title, 'scripted', false],
]);
