<?php

declare(strict_types=1);

namespace App\Services\Whisparr;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * The cached Whisparr library list, indexed by item id and read at most
 * once per connection per request (or queued job): describing a bulk of
 * titles reads — and unserializes — the cached list once, not once per
 * title. Bound scoped in AppServiceProvider: the index is per-request state.
 */
final class WhisparrItemIndex
{
    /**
     * @var array<int, array<int, array<string, mixed>>>
     */
    private array $itemsByConnection = [];

    /**
     * The library-list entry for the item, or null when the cached list does
     * not hold it (added since the list was cached). A failed read is not
     * remembered.
     *
     * @return array<string, mixed>|null
     *
     * @throws RequestException|ConnectionException
     */
    public function find(ServiceConnection $serviceConnection, int $itemId): ?array
    {
        $this->itemsByConnection[$serviceConnection->id] ??= $this->index(new WhisparrClient($serviceConnection)->getItems());

        return $this->itemsByConnection[$serviceConnection->id][$itemId] ?? null;
    }

    /**
     * The first entry wins for a repeated id, as the linear scan this
     * replaces did.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function index(array $items): array
    {
        $index = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_numeric($item['id'] ?? null)) {
                continue;
            }

            $index[(int) $item['id']] ??= $item;
        }

        return $index;
    }
}
