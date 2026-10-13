<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use RuntimeException;

/**
 * Pool accounting failed earlier in this request or job. The original failure
 * was already reported and is the previous throwable.
 */
final class PoolHeadroomUnavailable extends RuntimeException {}
