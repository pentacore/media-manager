<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;

trait WhisparrActionValidationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function whisparrTargetRules(): array
    {
        return [
            'service_connection_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function whisparrItemRules(): array
    {
        return [
            ...$this->whisparrTargetRules(),
            'item_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Strict pinning: the connection must exist, be active and be a Whisparr
     * connection. Item ids overlap between instances, so never fall back to
     * "any active Whisparr".
     */
    public function connection(): ServiceConnection
    {
        $connection = ServiceConnection::query()
            ->whereKey($this->integer('service_connection_id'))
            ->where('is_active', true)
            ->first();

        abort_unless(
            $connection instanceof ServiceConnection && $connection->type === ServiceType::Whisparr,
            422,
            'The selected connection is unavailable or is not a Whisparr connection.',
        );

        return $connection;
    }
}
