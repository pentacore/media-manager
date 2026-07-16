<?php

declare(strict_types=1);

namespace App\Cache\Services;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use InvalidArgumentException;

final class BazarrCache extends BaseServiceCache
{
    public function __construct(private readonly ServiceConnection $serviceConnection)
    {
        throw_if($this->serviceConnection->type !== ServiceType::Bazarr, InvalidArgumentException::class, 'BazarrCache requires a Bazarr service connection.');
        throw_if(! is_int($this->serviceConnection->id) || $this->serviceConnection->id <= 0, InvalidArgumentException::class, 'BazarrCache requires a persisted service connection with a positive ID.');
    }

    protected function service(): string
    {
        return 'bazarr';
    }

    protected function connectionId(): int
    {
        return $this->serviceConnection->id;
    }

    /**
     * @return array{list: int, entity: int, metadata: int}
     */
    protected function ttls(): array
    {
        return [
            'list' => 120,
            'entity' => 300,
            'metadata' => 600,
        ];
    }
}
