<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Validation\Rule;

trait ReplacementTargetValidationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function replacementTargetRules(): array
    {
        return [
            // Deliberate two-case subset of ServiceType (ruled): validationRule() would
            // accept all eight service types. Enum value constants keep it typo-proof.
            'service' => ['required', Rule::in([ServiceType::Sonarr->value, ServiceType::Radarr->value])],
            'service_connection_id' => ['required', 'integer'],
            'item_id' => ['required', 'integer', 'min:1'],
            'season_number' => ['required_if:service,sonarr', 'nullable', 'integer', 'min:0'],
            'episode_number' => ['required_if:service,sonarr', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Strict pinning: the connection must exist, be active, and match the
     * requested service. Never fall back to "any active connection of that
     * type" — media ids overlap between server instances.
     */
    public function connection(): ServiceConnection
    {
        $connection = ServiceConnection::query()
            ->whereKey($this->integer('service_connection_id'))
            ->where('is_active', true)
            ->first();

        abort_unless(
            $connection instanceof ServiceConnection
                && $connection->type === ServiceType::from($this->string('service')->value()),
            422,
            'The selected connection is unavailable or does not match the service.',
        );

        return $connection;
    }
}
