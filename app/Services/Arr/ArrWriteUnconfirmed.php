<?php

declare(strict_types=1);

namespace App\Services\Arr;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * An arr write (Sonarr, Radarr, Whisparr, Prowlarr) answered HTTP 200 with a
 * body that is not JSON data — almost always a reverse-proxy or SSO login
 * page in front of the service. The change may never have reached the arr,
 * or (behind an odd proxy) may have; nobody can tell from here.
 *
 * It extends RequestException so the controllers' existing
 * `RequestException|ConnectionException` catches report it as an upstream
 * failure. It is deliberately NOT ArrUnexpectedResponse: ExecuteActionRequest
 * retries that one, and a write whose outcome is unknown must never be sent
 * again — ExecuteActionRequest fails it for reconciliation instead. The
 * message is fixed: the parent would quote the body.
 */
final class ArrWriteUnconfirmed extends RequestException
{
    public function __construct(Response $response, string $service)
    {
        parent::__construct($response);

        $this->message = sprintf('%1$s answered the change with something other than its API data, so whether it was applied is unknown. Check %1$s before retrying.', $service);

        // RequestException::report() rebuilds $this->message from the raw
        // response unless this is already true.
        $this->hasBeenSummarized = true;
    }
}
