<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum MediaReplacementStatus: string
{
    use EnumUtils;

    case Requested = 'requested';
    case Downloading = 'downloading';
    case Imported = 'imported';
    case Verified = 'verified';
    case Failed = 'failed';
    case NeedsAttention = 'needs_attention';
}
