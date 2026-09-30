<?php

declare(strict_types=1);

namespace App\Services\Arr;

use RuntimeException;

final class SearchCommandFailed extends RuntimeException
{
    public static function unconfirmed(string $service): self
    {
        return new self(sprintf('%s did not confirm this library-wide search started. Check its logs before running it again — retrying blindly could queue duplicate searches.', $service));
    }
}
