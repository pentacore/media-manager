<?php

declare(strict_types=1);

namespace App\Services\Actions;

use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Runs one bulk request synchronously by calling the surface's single-item
 * path once per id, in order. The runner holds no permission, pinning or
 * approval logic of its own: the route middleware, the FormRequest and the
 * single-item service the closure calls are the same ones the single action
 * uses. A failed item never stops the rest.
 *
 * A run has a wall-clock budget below Octane's 150 s max_execution_time:
 * once it is spent, the remaining ids are failed without being attempted,
 * so a slow or unreachable upstream still ends in a summary that names
 * exactly what was not filed, instead of a killed request that hides the
 * items that already ran.
 */
final readonly class BulkRunner
{
    public const int MAX_ITEMS = 100;

    /** Seconds one run may spend before the remaining ids are left unattempted. */
    public const int BUDGET_SECONDS = 100;

    public function __construct(private int $budgetSeconds = self::BUDGET_SECONDS) {}

    /**
     * @template TId of int|string
     *
     * @param  list<TId>  $ids
     * @param  Closure(TId): BulkItemOutcome  $action  the surface's single-item path
     * @param  Closure(TId): string  $titleFor  a server-side display name, asked only for failed items
     *
     * @throws InvalidArgumentException when more than MAX_ITEMS ids, or a repeated id, are passed
     */
    public function run(array $ids, Closure $action, Closure $titleFor): BulkSummary
    {
        throw_if(count($ids) > self::MAX_ITEMS, InvalidArgumentException::class, sprintf('A bulk action takes at most %d items.', self::MAX_ITEMS));
        throw_if(count($ids) !== count(array_unique($ids, SORT_REGULAR)), InvalidArgumentException::class, 'A bulk action takes each id once.');

        $deadline = now()->addSeconds($this->budgetSeconds);
        $started = 0;
        $queued = 0;
        $skipped = 0;
        $failed = [];

        foreach ($ids as $id) {
            $bulkItemOutcome = $this->outOfTime($deadline)
                ? BulkItemOutcome::failed(__('Not attempted — the batch ran out of time.'))
                : $this->attempt($action, $id);

            if ($bulkItemOutcome->state === BulkItemOutcome::FAILED) {
                // Past the deadline even the title lookup (a live upstream
                // read on some surfaces) is skipped, so the budget holds.
                $title = $this->outOfTime($deadline) ? $this->fallbackTitle($id) : $this->title($titleFor, $id);
                $failed[] = ['id' => $id, 'title' => $title, 'reason' => (string) $bulkItemOutcome->reason];

                continue;
            }

            if ($bulkItemOutcome->state === BulkItemOutcome::STARTED) {
                $started++;
            } elseif ($bulkItemOutcome->state === BulkItemOutcome::QUEUED) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        return new BulkSummary(started: $started, queued: $queued, skipped: $skipped, failed: $failed);
    }

    private function outOfTime(CarbonImmutable $deadline): bool
    {
        return now()->greaterThanOrEqualTo($deadline);
    }

    /**
     * @template TId of int|string
     *
     * @param  Closure(TId): BulkItemOutcome  $action
     * @param  TId  $id
     */
    private function attempt(Closure $action, int|string $id): BulkItemOutcome
    {
        try {
            return $action($id);
        } catch (Throwable $throwable) {
            // Surface closures turn upstream errors into readable reasons
            // themselves; anything reaching here is a bug. Report it and
            // keep going, so the items that already ran still reach the
            // summary instead of a 500 that hides them.
            report($throwable);

            return BulkItemOutcome::failed(__('Something went wrong with this item — check the logs.'));
        }
    }

    /**
     * A failing title lookup (a malformed upstream payload) must not turn
     * a half-finished batch into a 500: it falls back to the id.
     *
     * @template TId of int|string
     *
     * @param  Closure(TId): string  $titleFor
     * @param  TId  $id
     */
    private function title(Closure $titleFor, int|string $id): string
    {
        try {
            return $titleFor($id);
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->fallbackTitle($id);
        }
    }

    private function fallbackTitle(int|string $id): string
    {
        return is_int($id) ? sprintf('#%d', $id) : $id;
    }
}
