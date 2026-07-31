<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MediaReplacementAttempt;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MediaReplacementAttemptChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public MediaReplacementAttempt $mediaReplacementAttempt,
    ) {}
}
