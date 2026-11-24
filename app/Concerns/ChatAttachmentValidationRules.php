<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Settings\AiSettings;
use Illuminate\Validation\Rules\File;

trait ChatAttachmentValidationRules
{
    /**
     * Up to the attachments-per-message setting of images, text files or
     * PDFs, of at most 10 MB each.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function chatAttachmentRules(): array
    {
        return [
            'attachments' => ['nullable', 'array', sprintf('max:%d', $this->chatMaxAttachments())],
            'attachments.*' => ['file', $this->chatAttachmentFileRule()],
        ];
    }

    /**
     * How many files one chat message, or one user turn of a chat benchmark case, may carry.
     */
    protected function chatMaxAttachments(): int
    {
        return resolve(AiSettings::class)->chatMaxAttachments();
    }

    /**
     * The type and size limits of one attached file.
     */
    protected function chatAttachmentFileRule(): File
    {
        return File::types(['png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'log', 'json', 'pdf'])->max(10 * 1024);
    }
}
