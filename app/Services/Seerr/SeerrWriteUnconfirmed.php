<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * A Seerr write answered HTTP 200 with a body that is not JSON data —
 * usually a reverse-proxy or SSO login page in front of Seerr, or a bare
 * JSON value. The change may never have reached Seerr, or (behind an odd
 * proxy) may have; nobody can tell from here.
 *
 * It extends RequestException so every existing
 * `RequestException|ConnectionException` catch treats it as an upstream
 * failure, but every caller that writes catches it first: a write whose
 * outcome is unknown must never be sent again. ExecuteActionRequest fails
 * it for reconciliation, and the controllers say the outcome is unknown.
 * The message is fixed (the parent would quote the body); controllers
 * flash MESSAGE, never getMessage().
 */
final class SeerrWriteUnconfirmed extends RequestException
{
    public const string MESSAGE = 'Seerr answered the change with something other than its API data, so whether it was applied is unknown. Check Seerr before retrying.';

    public function __construct(Response $response)
    {
        parent::__construct($response);

        $this->message = self::MESSAGE;

        // RequestException::report() rebuilds $this->message from the raw
        // response unless this is already true.
        $this->hasBeenSummarized = true;
    }
}
