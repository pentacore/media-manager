<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ChatAttachment;
use App\Models\User;
use App\Settings\AiSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Throwable;

/**
 * Chat attachments live on the local disk (so replay and history work for
 * any provider) and are uploaded to the provider's Files API only when no
 * failover is configured — a provider file id is useless to the failover.
 */
final readonly class ChatAttachmentStore
{
    private const string DISK = 'local';

    public function __construct(private AiSettings $aiSettings) {}

    /**
     * @param  array<int, UploadedFile>  $uploads
     * @return Collection<int, ChatAttachment>
     */
    public function store(User $user, array $uploads): Collection
    {
        return collect($uploads)->map(function (UploadedFile $uploadedFile) use ($user): ChatAttachment {
            $path = $uploadedFile->storeAs(
                sprintf('chat-attachments/%d', $user->id),
                sprintf('%s.%s', Str::uuid7(), $uploadedFile->extension() ?: 'bin'),
                self::DISK,
            );

            return ChatAttachment::query()->create([
                'user_id' => $user->id,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'mime_type' => $uploadedFile->getMimeType() ?? 'application/octet-stream',
                'size' => $uploadedFile->getSize(),
            ]);
        });
    }

    /**
     * @param  Collection<int, ChatAttachment>  $attachments
     * @return array<int, File>
     */
    public function toSdkAttachments(Collection $attachments): array
    {
        if ($attachments->isEmpty()) {
            return [];
        }

        $provider = $this->filesProvider();

        return $attachments->map(function (ChatAttachment $chatAttachment) use ($provider): File {
            $fileId = $provider === null ? null : $this->providerFileId($chatAttachment, $provider);

            return match (true) {
                $fileId !== null && $chatAttachment->isImage() => Image::fromId($fileId),
                $fileId !== null => Document::fromId($fileId),
                $chatAttachment->isImage() => Image::fromStorage($chatAttachment->path, $chatAttachment->disk),
                default => Document::fromStorage($chatAttachment->path, $chatAttachment->disk),
            };
        })->values()->all();
    }

    /**
     * @param  Collection<int, ChatAttachment>  $attachments
     */
    public function assignConversation(Collection $attachments, ?string $conversationId): void
    {
        if ($conversationId === null || $attachments->isEmpty()) {
            return;
        }

        ChatAttachment::query()
            ->whereKey($attachments->map(static fn (ChatAttachment $chatAttachment): int => $chatAttachment->id)->all())
            ->update(['conversation_id' => $conversationId]);
    }

    /**
     * Map a stored user message's `attachments` JSON (StoredImage /
     * StoredDocument arrays carrying a `path`) back to downloadable rows.
     *
     * @param  array<int, array<string, mixed>>  $storedAttachments
     * @return list<array{id: int, name: string, mime: string, url: string}>
     */
    public function forMessage(array $storedAttachments): array
    {
        $paths = array_values(array_filter(array_map(
            static fn (array $storedAttachment): ?string => is_string($storedAttachment['path'] ?? null) ? $storedAttachment['path'] : null,
            $storedAttachments,
        )));

        if ($paths === []) {
            return [];
        }

        return ChatAttachment::query()->whereIn('path', $paths)->get()
            ->map(fn (ChatAttachment $chatAttachment): array => [
                'id' => $chatAttachment->id,
                'name' => $chatAttachment->original_name,
                'mime' => $chatAttachment->mime_type,
                'url' => route('ai.chat.attachments.show', $chatAttachment),
            ])->values()->all();
    }

    /**
     * The provider to upload attachments to, or null to send them inline.
     */
    private function filesProvider(): ?string
    {
        if ($this->aiSettings->providerChain() !== null) {
            return null;
        }

        $primary = $this->aiSettings->primaryProvider()->value;

        try {
            return Ai::textProvider($primary) instanceof FileProvider ? $primary : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function providerFileId(ChatAttachment $chatAttachment, string $provider): ?string
    {
        $cached = $chatAttachment->provider_file_ids[$provider] ?? null;

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $source = $chatAttachment->isImage()
                ? Image::fromStorage($chatAttachment->path, $chatAttachment->disk)
                : Document::fromStorage($chatAttachment->path, $chatAttachment->disk);

            $fileId = $source->put(mimeType: $chatAttachment->mime_type, name: $chatAttachment->original_name, provider: $provider)->id;
        } catch (Throwable $throwable) {
            Log::warning('Chat attachment upload to provider failed; sending inline.', [
                'attachment_id' => $chatAttachment->id,
                'provider' => $provider,
                'exception' => $throwable::class,
            ]);

            return null;
        }

        $chatAttachment->update(['provider_file_ids' => [...($chatAttachment->provider_file_ids ?? []), $provider => $fileId]]);

        return $fileId;
    }
}
