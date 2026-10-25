<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use RuntimeException;

/**
 * No server holds the presented enrollment token.
 */
final class EnrollmentTokenUnknown extends RuntimeException {}
