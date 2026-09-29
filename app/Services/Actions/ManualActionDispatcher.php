<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * The one path a member's library action takes into the Action Queue:
 * server-verified description, `origin: 'manual'`, Action Rules applied.
 */
final readonly class ManualActionDispatcher
{
    public function __construct(
        private ActionDescriber $actionDescriber,
        private ActionOrchestrator $actionOrchestrator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $type, ServiceType $serviceType, array $payload, string $because): ManualActionOutcome
    {
        try {
            $description = $this->actionDescriber->describe($type, $payload)->because($because);
        } catch (UndescribableAction|InvalidArgumentException|ModelNotFoundException) {
            // monitor_episodes/search_media/grab_release resolve their pinned
            // connection strictly (ServiceConnection::resolvePinnedStrict()),
            // which throws InvalidArgumentException for a missing/wrong-type
            // pin and ModelNotFoundException for a deleted/deactivated one.
            // The describer resolves the same way, so both can surface here
            // in addition to UndescribableAction — none of them is a server
            // error, they all mean "refresh and try again".
            return new ManualActionOutcome(state: ManualActionOutcome::UNDESCRIBABLE);
        }

        $actionRequest = $this->actionOrchestrator->dispatch(
            type: $type,
            sourceService: $serviceType->value,
            targetService: $serviceType->value,
            payload: $payload,
            description: $description,
            origin: 'manual',
        );

        if (! $actionRequest instanceof ActionRequest) {
            return new ManualActionOutcome(state: ManualActionOutcome::DISABLED);
        }

        return new ManualActionOutcome(
            state: $actionRequest->status === ActionRequestStatus::Pending ? ManualActionOutcome::QUEUED : ManualActionOutcome::STARTED,
            actionRequest: $actionRequest,
        );
    }
}
