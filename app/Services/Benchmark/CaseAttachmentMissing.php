<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use RuntimeException;

/**
 * A benchmark case attachment's file is no longer stored. An expected,
 * user-fixable outcome: it is stored on the result, never reported.
 */
final class CaseAttachmentMissing extends RuntimeException {}
