<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Streaming\Events\Error;
use Throwable;

/**
 * A short, groupable code for why an AI run failed: the error type the
 * provider sent inside the stream (OpenAI's `server_error`), else the
 * exception's class in snake case, as tool failures store it.
 */
final class FailureCode
{
    public static function for(Throwable $throwable): string
    {
        $code = $throwable instanceof StreamErrorException && $throwable->error instanceof Error
            ? $throwable->error->type
            : Str::snake(class_basename($throwable));

        return mb_substr($code, 0, 64);
    }
}
