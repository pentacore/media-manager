<?php

declare(strict_types=1);

namespace App\Services\Actions;

use RuntimeException;

final class UndescribableAction extends RuntimeException
{
    public static function unsupportedType(string $type): self
    {
        return new self(sprintf('No description is defined for action type "%s".', $type));
    }

    public static function missingTarget(string $type, string $key): self
    {
        return new self(sprintf('Action type "%s" needs a positive "%s" in its payload.', $type, $key));
    }
}
