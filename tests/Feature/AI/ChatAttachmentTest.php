<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Storage::fake('local');
});

test('a streamed turn stores attachments and passes them to the agent', function (): void {
    MediaAgent::fake(['Looks like a codec error.']);
    Files::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('ai.chat.stream'), [
            'message' => 'What is this error?',
            'attachments' => [UploadedFile::fake()->image('error.png', 200, 100)],
        ], ['Accept' => 'text/event-stream'])
        ->assertOk()
        ->streamedContent();

    $attachment = ChatAttachment::sole();

    expect($attachment->user_id)->toBe($admin->id)
        ->and($attachment->conversation_id)->not->toBeNull()
        ->and($attachment->original_name)->toBe('error.png');
    Storage::disk('local')->assertExists($attachment->path);

    MediaAgent::assertPrompted(fn ($prompt): bool => $prompt->attachments->count() === 1);
});

test('attachments are validated for type, size and count', function (Closure $files, string $errorKey): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('ai.chat.stream'), ['message' => 'Hi', 'attachments' => $files()])
        ->assertSessionHasErrors($errorKey);
})->with([
    'wrong type' => [fn (): array => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')], 'attachments.0'],
    'too large' => [fn (): array => [UploadedFile::fake()->create('big.pdf', 10_241, 'application/pdf')], 'attachments.0'],
    'too many' => [fn (): array => array_map(static fn (int $i): UploadedFile => UploadedFile::fake()->image(sprintf('%d.png', $i)), [1, 2, 3, 4]), 'attachments'],
]);

test('only the owner can download an attachment', function (): void {
    $attachment = ChatAttachment::factory()->create();

    $this->actingAs($attachment->user)->get(route('ai.chat.attachments.show', $attachment))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('ai.chat.attachments.show', $attachment))->assertForbidden();
});
