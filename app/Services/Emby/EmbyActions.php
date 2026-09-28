<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class EmbyActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'emby_library_scan' => $this->libraryScan($actionRequest->payload),
            default => throw new InvalidArgumentException(sprintf('EmbyActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function libraryScan(array $payload): array
    {
        new EmbyClient($this->embyConnection($payload))->refreshLibrary();

        return ['triggered' => true];
    }

    /**
     * The Emby server EmbyLibraryScanScheduler coalesced this request for.
     * Requests queued without one (chat tool, older rows) scan the active
     * server; a named server that is gone or deactivated aborts.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ModelNotFoundException
     */
    private function embyConnection(array $payload): ServiceConnection
    {
        $connectionId = (int) ($payload['emby_connection_id'] ?? 0);

        if ($connectionId <= 0) {
            return ServiceConnection::resolveActive(ServiceType::Emby);
        }

        return ServiceConnection::query()
            ->whereKey($connectionId)
            ->where('type', ServiceType::Emby)
            ->where('is_active', true)
            ->firstOr(static fn (): never => throw new ModelNotFoundException(sprintf(
                'Emby connection %d this library scan was queued for is missing or deactivated.',
                $connectionId,
            ))->setModel(ServiceConnection::class, [$connectionId]));
    }
}
