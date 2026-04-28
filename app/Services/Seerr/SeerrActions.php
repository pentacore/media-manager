<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use InvalidArgumentException;

class SeerrActions implements ActionExecutor
{
    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        return match ($actionRequest->type) {
            'cleanup_seerr_request' => $this->cleanupRequest($actionRequest),
            'approve_seerr_request' => $this->approveRequest($actionRequest),
            'decline_seerr_request' => $this->declineRequest($actionRequest),
            default => throw new InvalidArgumentException(sprintf('SeerrActions cannot execute type "%s"', $actionRequest->type)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupRequest(ActionRequest $actionRequest): array
    {
        $payload = $actionRequest->payload;
        $requestId = (int) ($payload['seerr_request_id'] ?? 0);

        throw_if($requestId <= 0, InvalidArgumentException::class, 'seerr_request_id is required');

        $seerrClient = new SeerrClient(ServiceConnection::resolveActive(ServiceType::Seerr));
        $seerrClient->deleteRequest($requestId);

        return [
            'seerr_request_id' => $requestId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approveRequest(ActionRequest $actionRequest): array
    {
        $requestId = (int) ($actionRequest->payload['seerr_request_id'] ?? 0);

        throw_if($requestId <= 0, InvalidArgumentException::class, 'seerr_request_id is required');

        $seerrClient = new SeerrClient(ServiceConnection::resolveActive(ServiceType::Seerr));
        $seerrClient->updateRequestStatus($requestId, 'approve');

        return [
            'seerr_request_id' => $requestId,
            'status' => 'approved',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function declineRequest(ActionRequest $actionRequest): array
    {
        $requestId = (int) ($actionRequest->payload['seerr_request_id'] ?? 0);

        throw_if($requestId <= 0, InvalidArgumentException::class, 'seerr_request_id is required');

        $seerrClient = new SeerrClient(ServiceConnection::resolveActive(ServiceType::Seerr));
        $seerrClient->updateRequestStatus($requestId, 'decline');

        return [
            'seerr_request_id' => $requestId,
            'status' => 'declined',
        ];
    }
}
