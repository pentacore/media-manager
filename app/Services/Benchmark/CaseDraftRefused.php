<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use RuntimeException;

/**
 * An AI usage record that cannot be turned into a benchmark case.
 */
final class CaseDraftRefused extends RuntimeException {}
