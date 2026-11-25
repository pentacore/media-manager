<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\AiTask;
use App\Enums\AiUsageKind;
use App\Enums\UserRole;
use App\Models\AiUsageRecord;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Models\ChatAttachment;
use App\Models\User;
use App\Services\Benchmark\CaseAttachmentSync;
use App\Settings\AiSettings;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * The case body the form sends JSON-encoded as `payload`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chatCaseBody(array $overrides = []): array
{
    return array_replace_recursive([
        'task' => 'chat',
        'name' => 'Stuck download screenshot',
        'tags' => [],
        'notes' => null,
        'input' => ['prompt' => 'What does this error mean?', 'history' => [], 'mode' => null, 'conversation' => 'context'],
        'fixtures' => [],
        'scoring' => ['type' => 'judge', 'rubric_note' => null, 'pass_threshold' => 7],
    ], $overrides);
}

/**
 * What the form posts: the JSON body, the new files per turn and, for an update, the PUT spoof.
 *
 * @param  array<string, mixed>  $body
 * @param  array<array-key, list<UploadedFile>>  $files
 * @return array<string, mixed>
 */
function chatCaseMultipart(array $body, array $files = [], bool $update = false): array
{
    return [
        'payload' => json_encode($body, JSON_THROW_ON_ERROR),
        'attachments' => $files,
        ...($update ? ['_method' => 'PUT'] : []),
    ];
}

/**
 * @return list<array{role: string, content: string}>
 */
function chatCaseUserTurns(int $count): array
{
    return array_map(static fn (int $index): array => ['role' => 'user', 'content' => sprintf('Turn %d', $index)], range(1, $count));
}

function chatCaseWithHistory(): BenchmarkCase
{
    return BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => [
        'prompt' => 'What does this error mean?', 'mode' => null, 'conversation' => 'context',
        'history' => [
            ['role' => 'user', 'content' => 'First screenshot'],
            ['role' => 'assistant', 'content' => 'Looks like a stall.'],
            ['role' => 'user', 'content' => 'Here is the log.'],
        ],
    ]]);
}

/**
 * A chat turn with stored attachments, captured as an AI usage row, so its
 * attachments may be drafted into a case saved with that source invocation.
 *
 * @return array{0: string, 1: list<ChatAttachment>}
 */
function chatCaseCapturedAttachments(int $count = 1): array
{
    $conversationId = (string) Str::uuid();
    $turnAt = now()->startOfSecond()->subMinute();
    $chatAttachments = ChatAttachment::factory()->count($count)->sequence(fn (Sequence $sequence): array => ['conversation_id' => $conversationId, 'path' => sprintf('chat-attachments/1/%s-%d.png', $conversationId, $sequence->index)])->create()->all();

    DB::table('agent_conversations')->insert(['id' => $conversationId, 'title' => 'T', 'created_at' => $turnAt, 'updated_at' => $turnAt]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId, 'agent' => MediaAgent::class,
        'role' => 'user', 'content' => 'What does this error mean?', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]',
        'attachments' => json_encode(array_map(static fn (ChatAttachment $chatAttachment): array => ['type' => 'stored-image', 'name' => null, 'path' => $chatAttachment->path, 'disk' => 'local'], $chatAttachments)),
        'created_at' => $turnAt, 'updated_at' => $turnAt,
    ]);

    $aiUsageRecord = AiUsageRecord::factory()->create([
        'agent_class' => MediaAgent::class,
        'kind' => AiUsageKind::Text,
        'prompt_text' => "What does this error mean?\n\nAttachments: screenshot.png",
        'conversation_id' => $conversationId,
        'created_at' => $turnAt,
    ]);

    return [$aiUsageRecord->invocation_id, $chatAttachments];
}

test('new files are stored per turn in the case folder', function (): void {
    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
        'input' => ['history' => [['role' => 'user', 'content' => 'Here is the log.'], ['role' => 'assistant', 'content' => 'Thanks.']]],
    ]), [
        'final' => [UploadedFile::fake()->image('screen.png')],
        0 => [UploadedFile::fake()->create('download.log', 3, 'text/plain')],
    ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.benchmarks.cases.index'));

    $benchmarkCase = BenchmarkCase::query()->sole();
    $benchmarkCaseAttachment = $benchmarkCase->attachmentsFor(0)->sole();
    $screen = $benchmarkCase->attachmentsFor(null)->sole();

    expect($benchmarkCaseAttachment->original_name)->toBe('download.log')
        ->and($screen->original_name)->toBe('screen.png')
        ->and($screen->mime_type)->toBe('image/png')
        ->and($screen->path)->toStartWith(sprintf('benchmark-cases/%d/', $benchmarkCase->id))
        ->and($benchmarkCase->input['conversation'])->toBe('context')
        ->and($benchmarkCase->input['history'])->toBe([['role' => 'user', 'content' => 'Here is the log.'], ['role' => 'assistant', 'content' => 'Thanks.']]);
    Storage::disk('local')->assertExists([$benchmarkCaseAttachment->path, $screen->path]);
});

test('the JSON payload keeps tool argument types a multipart form would turn into strings', function (): void {
    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
        'fixtures' => [['tool' => 'MonitorMediaTool', 'arguments' => ['monitored' => true, 'id' => 7], 'result' => '{}']],
    ]), ['final' => [UploadedFile::fake()->image('screen.png')]]))->assertSessionHasNoErrors();

    $arguments = BenchmarkCase::query()->sole()->fixtures[0]['arguments'];

    expect($arguments['monitored'])->toBeTrue()
        ->and($arguments['id'])->toBe(7)
        ->and($arguments)->toHaveCount(2);
});

test('removing a history turn deletes its attachments and renumbers the later ones', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    $removed = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->forTurn(0)->create();
    $moved = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->forTurn(2)->create();
    $final = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();

    $this->post(route('admin.benchmarks.cases.update', $benchmarkCase), chatCaseMultipart(chatCaseBody([
        'input' => ['history' => [['role' => 'user', 'content' => 'Here is the log.']]],
        'keep_attachment_ids' => ['0' => [$moved->id], 'final' => [$final->id]],
    ]), update: true))->assertSessionHasNoErrors()->assertRedirect(route('admin.benchmarks.cases.index'));

    expect(BenchmarkCaseAttachment::query()->find($removed->id))->toBeNull()
        ->and($moved->fresh()->turn)->toBe(0)
        ->and($final->fresh()->turn)->toBeNull();
    Storage::disk('local')->assertMissing($removed->path);
    Storage::disk('local')->assertExists([$moved->path, $final->path]);
});

test('saving without touching the attachments keeps every one of them', function (bool $sendsKeepList): void {
    $benchmarkCase = chatCaseWithHistory();
    $first = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->forTurn(0)->create();
    $final = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    $body = chatCaseBody(['name' => 'Renamed', 'input' => ['history' => [
        ['role' => 'user', 'content' => 'First screenshot'],
        ['role' => 'assistant', 'content' => 'Looks like a stall.'],
        ['role' => 'user', 'content' => 'Here is the log.'],
    ]]]);

    $response = $sendsKeepList
        ? $this->post(route('admin.benchmarks.cases.update', $benchmarkCase), chatCaseMultipart([...$body, 'keep_attachment_ids' => ['0' => [$first->id], 'final' => [$final->id]]], update: true))
        : $this->put(route('admin.benchmarks.cases.update', $benchmarkCase), $body);

    $response->assertSessionHasNoErrors();

    expect($benchmarkCase->fresh()->name)->toBe('Renamed')
        ->and(BenchmarkCaseAttachment::query()->orderBy('id')->get()->map(static fn (BenchmarkCaseAttachment $attachment): array => [$attachment->id, $attachment->turn])->all())
        ->toBe([[$first->id, 0], [$final->id, null]]);
    Storage::disk('local')->assertExists([$first->path, $final->path]);
})->with([
    'the form keep list' => [true],
    'a JSON update without one' => [false],
]);

test('an empty keep map in the form payload deletes every saved attachment', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    $final = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();

    $this->post(route('admin.benchmarks.cases.update', $benchmarkCase), chatCaseMultipart(chatCaseBody([
        'input' => ['history' => [
            ['role' => 'user', 'content' => 'First screenshot'],
            ['role' => 'assistant', 'content' => 'Looks like a stall.'],
            ['role' => 'user', 'content' => 'Here is the log.'],
        ]],
        'keep_attachment_ids' => (object) [],
    ]), update: true))->assertSessionHasNoErrors();

    expect(BenchmarkCaseAttachment::query()->find($final->id))->toBeNull();
    Storage::disk('local')->assertMissing($final->path);
});

test('a draft attachment is copied into the case folder on save and the chat file stays', function (): void {
    [$invocationId, [$chatAttachment]] = chatCaseCapturedAttachments();

    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
        'source_invocation_id' => $invocationId,
        'draft_attachment_ids' => ['final' => [$chatAttachment->id]],
    ])))->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');

    $benchmarkCaseAttachment = BenchmarkCaseAttachment::query()->sole();
    expect($benchmarkCaseAttachment->turn)->toBeNull()
        ->and($benchmarkCaseAttachment->original_name)->toBe('screenshot.png')
        ->and($benchmarkCaseAttachment->mime_type)->toBe('image/png')
        ->and($benchmarkCaseAttachment->path)->toStartWith(sprintf('benchmark-cases/%d/', $benchmarkCaseAttachment->benchmark_case_id))
        ->and(Storage::disk('local')->get($benchmarkCaseAttachment->path))->toBe('png-bytes');
    Storage::disk('local')->assertExists($chatAttachment->path);
});

test('a draft attachment whose file is gone is skipped and the toast says so', function (): void {
    [$invocationId, [$chatAttachment]] = chatCaseCapturedAttachments();
    Storage::disk('local')->delete($chatAttachment->path);

    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
        'source_invocation_id' => $invocationId,
        'draft_attachment_ids' => ['final' => [$chatAttachment->id]],
    ])))
        ->assertRedirect(route('admin.benchmarks.cases.index'))
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', 'Benchmark case created, but 1 attachment could not be copied because the file is no longer stored.');

    expect(BenchmarkCase::query()->count())->toBe(1)
        ->and(BenchmarkCaseAttachment::query()->count())->toBe(0);
});

test('attachments and the replay mode are validated', function (array $payload, string $errorKey): void {
    $this->post(route('admin.benchmarks.cases.store'), $payload)->assertSessionHasErrors($errorKey);

    expect(BenchmarkCase::query()->count())->toBe(0);
})->with([
    'four files on the prompt' => [fn (): array => chatCaseMultipart(chatCaseBody(), ['final' => array_map(static fn (int $i): UploadedFile => UploadedFile::fake()->image(sprintf('%d.png', $i)), [1, 2, 3, 4])]), 'attachments.final'],
    'two files and two draft copies on the prompt' => [function (): array {
        [$invocationId, $chatAttachments] = chatCaseCapturedAttachments(2);

        return chatCaseMultipart(chatCaseBody(['source_invocation_id' => $invocationId, 'draft_attachment_ids' => ['final' => array_map(static fn (ChatAttachment $chatAttachment): int => $chatAttachment->id, $chatAttachments)]]), ['final' => [UploadedFile::fake()->image('1.png'), UploadedFile::fake()->image('2.png')]]);
    }, 'attachments.final'],
    'a draft copy from outside the captured conversation' => [function (): array {
        [$invocationId] = chatCaseCapturedAttachments();

        return chatCaseMultipart(chatCaseBody(['source_invocation_id' => $invocationId, 'draft_attachment_ids' => ['final' => [ChatAttachment::factory()->create()->id]]]));
    }, 'draft_attachment_ids.final'],
    'a draft copy without a captured invocation' => [fn (): array => chatCaseMultipart(chatCaseBody(['draft_attachment_ids' => ['final' => [ChatAttachment::factory()->create()->id]]])), 'draft_attachment_ids.final'],
    'an executable' => [fn (): array => chatCaseMultipart(chatCaseBody(), ['final' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')]]), 'attachments.final.0'],
    'a file over 10 MB' => [fn (): array => chatCaseMultipart(chatCaseBody(), ['final' => [UploadedFile::fake()->create('big.pdf', 10_241, 'application/pdf')]]), 'attachments.final.0'],
    'a file on an assistant turn' => [fn (): array => chatCaseMultipart(chatCaseBody(['input' => ['history' => [['role' => 'assistant', 'content' => 'Hi']]]]), [0 => [UploadedFile::fake()->image('1.png')]]), 'attachments.0'],
    'a file on a turn that does not exist' => [fn (): array => chatCaseMultipart(chatCaseBody(), [5 => [UploadedFile::fake()->image('1.png')]]), 'attachments.5'],
    'a file on a title case' => [fn (): array => chatCaseMultipart(chatCaseBody(['task' => 'title']), ['final' => [UploadedFile::fake()->image('1.png')]]), 'attachments'],
    'an unknown replay mode' => [fn (): array => chatCaseMultipart(chatCaseBody(['input' => ['conversation' => 'wild']])), 'input.conversation'],
    'eleven scripted user turns' => [fn (): array => chatCaseMultipart(chatCaseBody(['input' => ['conversation' => 'scripted', 'history' => chatCaseUserTurns(10)]])), 'input.conversation'],
]);

test('a scripted case may have ten user turns including the prompt', function (): void {
    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
        'input' => ['conversation' => 'scripted', 'history' => chatCaseUserTurns(9)],
    ])))->assertSessionHasNoErrors();

    expect(BenchmarkCase::query()->sole()->isScripted())->toBeTrue();
});

test('the edit page lists the case attachments by turn', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    $first = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->forTurn(0)->create();
    $final = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();

    $this->get(route('admin.benchmarks.cases.edit', $benchmarkCase))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('case.input.conversation', 'context')
            ->where('case.attachments', [
                ['id' => $first->id, 'turn' => 0, 'name' => 'download.log', 'mime_type' => 'text/plain', 'size' => 64, 'url' => route('admin.benchmarks.cases.attachments.show', [$benchmarkCase, $first])],
                ['id' => $final->id, 'turn' => null, 'name' => 'screenshot.png', 'mime_type' => 'image/png', 'size' => 1024, 'url' => route('admin.benchmarks.cases.attachments.show', [$benchmarkCase, $final])],
            ]));
});

test('the index rows count attachments and flag scripted cases', function (): void {
    $benchmarkCase = BenchmarkCase::factory()->forTask(AiTask::Chat)->judged()->create(['input' => ['prompt' => 'p', 'conversation' => 'scripted']]);
    BenchmarkCaseAttachment::factory()->count(2)->for($benchmarkCase)->image()->create();

    $this->get(route('admin.benchmarks.cases.index'))
        ->assertInertia(fn ($page) => $page
            ->where('cases.data.0.attachment_count', 2)
            ->where('cases.data.0.scripted', true));
});

test('a JSON update without a keep list counts the saved attachments toward the per-turn cap', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    BenchmarkCaseAttachment::factory()->count(3)->for($benchmarkCase)->image()->create();
    $body = chatCaseBody(['input' => ['history' => [
        ['role' => 'user', 'content' => 'First screenshot'],
        ['role' => 'assistant', 'content' => 'Looks like a stall.'],
        ['role' => 'user', 'content' => 'Here is the log.'],
    ]]]);

    $this->post(route('admin.benchmarks.cases.update', $benchmarkCase), chatCaseMultipart($body, ['final' => [UploadedFile::fake()->image('4.png')]], update: true))
        ->assertSessionHasErrors(['attachments.final' => 'A turn can carry at most 3 attachments.']);

    expect($benchmarkCase->attachments()->count())->toBe(3);
});

test('the per-turn cap follows the attachments-per-message setting', function (): void {
    resolve(AiSettings::class)->setChatMaxAttachments(1);

    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody(), ['final' => [UploadedFile::fake()->image('1.png'), UploadedFile::fake()->image('2.png')]]))
        ->assertSessionHasErrors(['attachments.final' => 'A turn can carry at most 1 attachment.']);

    resolve(AiSettings::class)->setChatMaxAttachments(5);

    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody(), ['final' => array_map(static fn (int $i): UploadedFile => UploadedFile::fake()->image(sprintf('%d.png', $i)), [1, 2, 3, 4, 5])]))
        ->assertSessionHasNoErrors();

    expect(BenchmarkCaseAttachment::query()->count())->toBe(5);
});

test('the case form gets the attachments-per-message setting', function (): void {
    resolve(AiSettings::class)->setChatMaxAttachments(4);

    $this->get(route('admin.benchmarks.cases.create'))
        ->assertInertia(fn ($page) => $page->where('maxAttachments', 4));
});

test('an upload the disk refuses fails the save and removes the files already copied', function (): void {
    [, [$chatAttachment]] = chatCaseCapturedAttachments();
    $benchmarkCase = chatCaseWithHistory();
    $refused = Mockery::mock(UploadedFile::fake()->image('refused.png'))->makePartial();
    $refused->shouldReceive('storeAs')->andReturn(false);

    expect(fn (): int => resolve(CaseAttachmentSync::class)->sync($benchmarkCase, null, ['final' => [$chatAttachment->id]], ['final' => [$refused]]))
        ->toThrow(RuntimeException::class, 'The attachment refused.png could not be stored.');

    expect($benchmarkCase->attachments()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles(BenchmarkCaseAttachment::directoryFor($benchmarkCase->id)))->toBe([]);
    Storage::disk('local')->assertExists($chatAttachment->path);
});

test('a saved attachment can be previewed: images inline, other files as a download', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    $image = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    $log = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->text()->forTurn(0)->create();

    $imageResponse = $this->get(route('admin.benchmarks.cases.attachments.show', [$benchmarkCase, $image]))->assertOk();
    $logResponse = $this->get(route('admin.benchmarks.cases.attachments.show', [$benchmarkCase, $log]))->assertOk();

    expect($imageResponse->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($imageResponse->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($logResponse->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($logResponse->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

test('an attachment is previewed only through its own case', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    $other = BenchmarkCaseAttachment::factory()->image()->create();

    $this->get(route('admin.benchmarks.cases.attachments.show', [$benchmarkCase, $other]))->assertNotFound();
});

test('a previewed attachment whose file is gone is a 404', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    $image = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();
    Storage::disk('local')->delete($image->path);

    $this->get(route('admin.benchmarks.cases.attachments.show', [$benchmarkCase, $image]))->assertNotFound();
});

test('members cannot preview case attachments', function (): void {
    $image = BenchmarkCaseAttachment::factory()->image()->create();
    $this->actingAs(User::factory()->member()->create());

    $this->get(route('admin.benchmarks.cases.attachments.show', [$image->benchmark_case_id, $image]))->assertForbidden();
});

test('the edit page links each saved attachment to its preview', function (): void {
    $benchmarkCase = chatCaseWithHistory();
    $image = BenchmarkCaseAttachment::factory()->for($benchmarkCase)->image()->create();

    $this->get(route('admin.benchmarks.cases.edit', $benchmarkCase))
        ->assertInertia(fn ($page) => $page->where('case.attachments.0.url', route('admin.benchmarks.cases.attachments.show', [$benchmarkCase, $image])));
});

test('a drafted chat attachment of another user can be previewed through the usage row it was captured from', function (): void {
    [$invocationId, [$image]] = chatCaseCapturedAttachments();
    $aiUsageRecord = AiUsageRecord::query()->where('invocation_id', $invocationId)->sole();
    $log = ChatAttachment::factory()->create(['conversation_id' => $image->conversation_id, 'path' => 'chat-attachments/1/download.log', 'original_name' => 'download.log', 'mime_type' => 'text/plain']);
    DB::table('agent_conversation_messages')->where('conversation_id', $image->conversation_id)->update(['attachments' => json_encode([
        ['type' => 'stored-image', 'name' => null, 'path' => $image->path, 'disk' => 'local'],
        ['type' => 'stored-document', 'name' => null, 'path' => $log->path, 'disk' => 'local'],
    ])]);

    expect($image->user_id)->not->toBe(auth()->id());

    $imageResponse = $this->get(route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $image]))->assertOk();
    $logResponse = $this->get(route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $log]))->assertOk();

    expect($imageResponse->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($imageResponse->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($imageResponse->streamedContent())->toBe('png-bytes')
        ->and($logResponse->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($logResponse->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

test('a chat file the usage row did not draft cannot be previewed through it', function (): void {
    [$invocationId] = chatCaseCapturedAttachments();
    $aiUsageRecord = AiUsageRecord::query()->where('invocation_id', $invocationId)->sole();
    [, [$elsewhere]] = chatCaseCapturedAttachments();
    $unattached = ChatAttachment::factory()->create(['conversation_id' => $aiUsageRecord->conversation_id, 'path' => 'chat-attachments/1/unsent.png']);

    $this->get(route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $elsewhere]))->assertNotFound();
    $this->get(route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $unattached]))->assertNotFound();
});

test('a drafted chat attachment is not previewed through a usage row that cannot be captured', function (): void {
    [$invocationId, [$chatAttachment]] = chatCaseCapturedAttachments();
    $aiUsageRecord = AiUsageRecord::query()->where('invocation_id', $invocationId)->sole();
    $aiUsageRecord->update(['is_benchmark' => true]);

    $this->get(route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $chatAttachment]))->assertNotFound();
});

test('a drafted chat attachment whose file is gone is a 404', function (): void {
    [$invocationId, [$chatAttachment]] = chatCaseCapturedAttachments();
    $aiUsageRecord = AiUsageRecord::query()->where('invocation_id', $invocationId)->sole();
    Storage::disk('local')->delete($chatAttachment->path);

    $this->get(route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $chatAttachment]))->assertNotFound();
});

test('members cannot preview drafted chat attachments, not even their own', function (): void {
    [$invocationId, [$chatAttachment]] = chatCaseCapturedAttachments();
    $aiUsageRecord = AiUsageRecord::query()->where('invocation_id', $invocationId)->sole();
    $chatAttachment->user->update(['role' => UserRole::Member]);
    $this->actingAs($chatAttachment->user->refresh());

    $this->get(route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $chatAttachment]))->assertForbidden();
});

test('the create page links each drafted attachment to its preview', function (): void {
    [$invocationId, [$chatAttachment]] = chatCaseCapturedAttachments();
    $aiUsageRecord = AiUsageRecord::query()->where('invocation_id', $invocationId)->sole();

    $this->get(route('admin.benchmarks.cases.create', ['from_usage' => $aiUsageRecord->id]))
        ->assertInertia(fn ($page) => $page->where('draft.draft_attachments.0.url', route('admin.benchmarks.cases.draft-attachments.show', [$aiUsageRecord, $chatAttachment])));
});
