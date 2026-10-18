<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Services\Seerr\SeerrReadFailure;
use Closure;
use Illuminate\Http\Client\RequestException;

/**
 * A Seerr read tool's answer when Seerr refuses the read: a login page in
 * front of Seerr or a rejected API key gets the same answer on every retry,
 * so the model is told to stop and hand it to an admin (`seerr_refused`).
 * An unknown title (404), an outage and anything else are rethrown for
 * BaseTool's generic `tool_failed`.
 */
trait AnswersSeerrRefusals
{
    /**
     * @param  Closure(): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    protected function readSeerr(Closure $read): array
    {
        try {
            return $read();
        } catch (RequestException $requestException) {
            throw_if($requestException->response->status() === 404 || ! SeerrReadFailure::isRefusal($requestException), $requestException);

            return [
                'error' => 'seerr_refused',
                'message' => 'Seerr refused the lookup: it answered without its API data, usually because a login page sits in front of Seerr or its API key was rejected. Retrying will not help. Tell the user an admin needs to check the Seerr connection.',
            ];
        }
    }
}
