<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use RuntimeException;

/**
 * Whisparr answered a read with HTTP 200 but a body that is not a JSON array
 * or object — an SSO or reverse-proxy login page, an HTML error page, or a
 * bare scalar. WhisparrClient throws this instead of returning (and caching)
 * an empty list, so an outage never renders as an empty library; the
 * Whisparr controllers word it as "Whisparr is unreachable right now."
 */
final class WhisparrUnexpectedResponse extends RuntimeException {}
