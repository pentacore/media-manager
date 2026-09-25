<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Validation\Rules\File;

trait ChatAttachmentValidationRules
{
    /**
     * Up to three images, text files or PDFs of at most 10 MB each.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function chatAttachmentRules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', File::types(['png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'log', 'json', 'pdf'])->max(10 * 1024)],
        ];
    }
}
