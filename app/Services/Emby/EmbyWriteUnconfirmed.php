<?php

declare(strict_types=1);

namespace App\Services\Emby;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * An Emby write answered HTTP 200 with a body that is not JSON data —
 * usually a reverse-proxy or SSO login page in front of Emby. The change may
 * never have reached Emby, or (behind an odd proxy) may have; nobody can tell
 * from here.
 *
 * It extends RequestException so every existing
 * `RequestException|ConnectionException` catch treats it as an upstream
 * failure, but every caller that writes catches it first: a write whose
 * outcome is unknown must never be sent again, and must never read as a
 * success. The message is fixed (the parent would quote the body).
 */
final class EmbyWriteUnconfirmed extends RequestException
{
    public const string MESSAGE = 'Emby answered the change with something other than its API data, so whether it was applied is unknown. Check Emby before retrying.';

    public function __construct(Response $response)
    {
        parent::__construct($response);

        $this->message = self::MESSAGE;

        // RequestException::report() rebuilds $this->message from the raw
        // response unless this is already true.
        $this->hasBeenSummarized = true;
    }
}
