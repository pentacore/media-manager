<?php

declare(strict_types=1);

namespace App\Services\HostMonitoring;

use RuntimeException;

/**
 * The enrollment token belongs to a server, but it was already used or has expired.
 */
final class EnrollmentTokenSpent extends RuntimeException {}
