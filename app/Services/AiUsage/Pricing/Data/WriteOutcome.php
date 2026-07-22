<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

use App\Concerns\EnumUtils;

/**
 * The result of a single automatic write attempt against the AI model price
 * catalog. Dry-run attempts report the would-be outcome without persisting.
 */
enum WriteOutcome: string
{
    use EnumUtils;

    /**
     * A new price row was created.
     */
    case Created = 'created';

    /**
     * An existing price row was updated with changed values.
     */
    case Updated = 'updated';

    /**
     * An existing row matched the candidate; nothing was written.
     */
    case Unchanged = 'unchanged';

    /**
     * The target row is locked; the automatic write was skipped.
     */
    case Locked = 'locked';

    /**
     * The candidate failed validation or scope checks.
     */
    case Rejected = 'rejected';

    /**
     * The candidate passed validation and scope but was withheld solely by the
     * relative-change anomaly guard; callers may queue the pair for scoped
     * first-party verification, which can bypass the guard.
     */
    case RejectedAnomalous = 'rejected_anomalous';

    /**
     * Dry run: a create would have occurred.
     */
    case WouldCreate = 'would_create';

    /**
     * Dry run: an update would have occurred.
     */
    case WouldUpdate = 'would_update';
}
