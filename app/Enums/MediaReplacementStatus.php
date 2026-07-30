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

    /**
     * Whether the attempt has settled: it either verified, was given up on, or
     * needs a human. Background work must not keep acting on the service's
     * behalf past this point — a sweep, for instance, could then only remove a
     * download that is no longer ours.
     *
     * The match is exhaustive on purpose. A new case must decide here rather
     * than default silently to non-terminal, which is the direction that keeps
     * background work running against a finished attempt.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Verified, self::Failed, self::NeedsAttention => true,
            self::Requested, self::Downloading, self::Imported => false,
        };
    }
}
