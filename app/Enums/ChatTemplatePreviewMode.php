<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * How the template editor's live preview fills placeholders.
 */
enum ChatTemplatePreviewMode: string
{
    use EnumUtils;

    /** Sample values, so the preview reads like the message the assistant gets. */
    case Example = 'example';

    /** Every placeholder as its label, so the template's structure stays visible. */
    case Names = 'names';

    public function label(): string
    {
        return match ($this) {
            self::Example => 'Example values',
            self::Names => 'Placeholder names',
        };
    }
}
