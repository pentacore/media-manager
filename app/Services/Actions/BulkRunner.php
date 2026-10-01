<?php

declare(strict_types=1);

namespace App\Services\Actions;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Runs one bulk request synchronously by calling the surface's single-item
 * path once per id, in order. The runner holds no permission, pinning or
 * approval logic of its own: the route middleware, the FormRequest and the
 * single-item service the closure calls are the same ones the single action
 * uses. A failed item never stops the rest.
 */
final readonly class BulkRunner
{
    public const int MAX_ITEMS = 100;

    /**
     * @template TId of int|string
     *
     * @param  list<TId>  $ids
     * @param  Closure(TId): BulkItemOutcome  $action  the surface's single-item path
     * @param  Closure(TId): string  $titleFor  a server-side display name, asked only for failed items
     *
     * @throws InvalidArgumentException when more than MAX_ITEMS ids are passed
     */
    public function run(array $ids, Closure $action, Closure $titleFor): BulkSummary
    {
        throw_if(count($ids) > self::MAX_ITEMS, InvalidArgumentException::class, sprintf('A bulk action takes at most %d items.', self::MAX_ITEMS));

        $started = 0;
        $queued = 0;
        $skipped = 0;
        $failed = [];

        foreach ($ids as $id) {
            try {
                $bulkItemOutcome = $action($id);
            } catch (Throwable $throwable) {
                // Surface closures turn upstream errors into readable reasons
                // themselves; anything reaching here is a bug. Report it and
                // keep going, so the items that already ran still reach the
                // summary instead of a 500 that hides them.
                report($throwable);
                $bulkItemOutcome = BulkItemOutcome::failed(__('Something went wrong with this item — check the logs.'));
            }

            if ($bulkItemOutcome->state === BulkItemOutcome::FAILED) {
                $failed[] = ['id' => $id, 'title' => $titleFor($id), 'reason' => (string) $bulkItemOutcome->reason];

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
}
