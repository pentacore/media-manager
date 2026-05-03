<?php

declare(strict_types=1);

namespace App\Services\Emby;

final class BackfillResult
{
    public function __construct(
        public int $itemsFetched,
        public int $itemsCreated,
        public int $itemsUpdated,
        public int $itemsSkipped,
    ) {}
}
