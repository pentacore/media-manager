<?php

declare(strict_types=1);

namespace App\Services\Arr;

use RuntimeException;

final class ReleaseGrabFailed extends RuntimeException
{
    public static function expired(string $service): self
    {
        return new self(sprintf('%s no longer has this release in its search results (they expire after 30 minutes). Run the interactive search again and pick it again.', $service));
    }

    public static function unconfirmed(string $service): self
    {
        return new self(sprintf('%s did not confirm the grab. Check its queue before grabbing again.', $service));
    }
}
