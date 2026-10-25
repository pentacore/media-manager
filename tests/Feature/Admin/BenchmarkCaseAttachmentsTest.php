<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

test('new files are stored per turn in the case folder', function (): void {
    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
        'input' => ['history' => [['role' => 'user', 'content' => 'Here is the log.'], ['role' => 'assistant', 'content' => 'Thanks.']]],
    ]), [
        'final' => [UploadedFile::fake()->image('screen.png')],
        0 => [UploadedFile::fake()->create('download.log', 3, 'text/plain')],
    ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.benchmarks.cases.index'));

    $benchmarkCase = BenchmarkCase::query()->sole();
    $log = $benchmarkCase->attachmentsFor(0)->sole();
    $screen = $benchmarkCase->attachmentsFor(null)->sole();

    expect($log->original_name)->toBe('download.log')
        ->and($screen->original_name)->toBe('screen.png')
        ->and($screen->mime_type)->toBe('image/png')
        ->and($screen->path)->toStartWith(sprintf('benchmark-cases/%d/', $benchmarkCase->id))
        ->and($benchmarkCase->input['conversation'])->toBe('context')
        ->and($benchmarkCase->input['history'])->toBe([['role' => 'user', 'content' => 'Here is the log.'], ['role' => 'assistant', 'content' => 'Thanks.']]);
    Storage::disk('local')->assertExists([$log->path, $screen->path]);
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

test('a draft attachment is copied into the case folder on save and the chat file stays', function (): void {
    $chatAttachment = ChatAttachment::factory()->create();

    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
        'draft_attachment_ids' => ['final' => [$chatAttachment->id]],
    ])))->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');

    $attachment = BenchmarkCaseAttachment::query()->sole();
    expect($attachment->turn)->toBeNull()
        ->and($attachment->original_name)->toBe('screenshot.png')
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($attachment->path)->toStartWith(sprintf('benchmark-cases/%d/', $attachment->benchmark_case_id))
        ->and(Storage::disk('local')->get($attachment->path))->toBe('png-bytes');
    Storage::disk('local')->assertExists($chatAttachment->path);
});

test('a draft attachment whose file is gone is skipped and the toast says so', function (): void {
    $chatAttachment = ChatAttachment::factory()->create();
    Storage::disk('local')->delete($chatAttachment->path);

    $this->post(route('admin.benchmarks.cases.store'), chatCaseMultipart(chatCaseBody([
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
    'two files and two draft copies on the prompt' => [fn (): array => chatCaseMultipart(chatCaseBody(['draft_attachment_ids' => ['final' => ChatAttachment::factory()->count(2)->create()->modelKeys()]]), ['final' => [UploadedFile::fake()->image('1.png'), UploadedFile::fake()->image('2.png')]]), 'attachments.final'],
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
                ['id' => $first->id, 'turn' => 0, 'name' => 'download.log', 'mime_type' => 'text/plain', 'size' => 64],
                ['id' => $final->id, 'turn' => null, 'name' => 'screenshot.png', 'mime_type' => 'image/png', 'size' => 1024],
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
