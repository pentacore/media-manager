<?php

declare(strict_types=1);

namespace App\Services\Arr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Posts an interactive-search pick back to Sonarr/Radarr. They look the
 * release up by `{indexerId}_{guid}` in a 30-minute cache and answer 404 on a
 * miss. A lost response or 5xx is indeterminate — the grab may have started —
 * so it becomes a permanent failure (never a queue retry) that tells the
 * member to check the queue first.
 */
final readonly class ReleaseGrabber
{
    /**
     * @throws ReleaseGrabFailed|RequestException
     */
    public function grab(ArrClient $arrClient, string $service, string $guid, int $indexerId): void
    {
        try {
            $arrClient->grabRelease(['guid' => $guid, 'indexerId' => $indexerId]);
        } catch (ConnectionException) {
            throw ReleaseGrabFailed::unconfirmed($service);
        } catch (RequestException $requestException) {
            throw_if($requestException->response->status() === 404, ReleaseGrabFailed::expired($service));
            throw_if($requestException->response->serverError(), ReleaseGrabFailed::unconfirmed($service));

            throw $requestException;
        }
    }
}
