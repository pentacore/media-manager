<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Services\Arr\ArrClient;

/**
 * Unified Whisparr client. The configured WhisparrVersion selects the upstream
 * API resource (`movie` for v3, `series` for v2). Methods are fleshed out in a
 * later task.
 */
class WhisparrClient extends ArrClient {}
