<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\BenchmarkScheduleOutcome;
use App\Models\BenchmarkRun;

/** What one launch attempt of a benchmark schedule did. */
final readonly class BenchmarkScheduleAttempt
{
    public function __construct(
        public BenchmarkScheduleOutcome $outcome,
        public string $message,
        public ?BenchmarkRun $benchmarkRun = null,
    ) {}
}
