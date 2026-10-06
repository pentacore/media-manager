<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Validation\Rule;

trait MediaActionValidationRules
{
    /**
     * `origin` names the page a request came from when it is not the library
     * pages, for the action reason (MediaActionController::because()).
     *
     * @return array<string, mixed>
     */
    protected function mediaActionTargetRules(): array
    {
        return [
            'service' => ['required', Rule::in([ServiceType::Sonarr->value, ServiceType::Radarr->value])],
            'service_connection_id' => ['required', 'integer'],
            'origin' => ['nullable', Rule::in(['seasonal_anime'])],
        ];
    }

    /**
     * Strict pinning: the connection must exist, be active and match the
     * service. Media ids overlap between instances, so never fall back to
     * "any active connection of that type".
     */
    public function connection(): ServiceConnection
    {
        $connection = ServiceConnection::query()
            ->whereKey($this->integer('service_connection_id'))
            ->where('is_active', true)
            ->first();

        abort_unless(
            $connection instanceof ServiceConnection && $connection->type === $this->serviceType(),
            422,
            'The selected connection is unavailable or does not match the service.',
        );

        return $connection;
    }

    public function serviceType(): ServiceType
    {
        return ServiceType::from($this->string('service')->value());
    }
}
