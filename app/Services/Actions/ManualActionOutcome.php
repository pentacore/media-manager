<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Models\ActionRequest;

/**
 * What happened to a member's library action: started right away, queued for
 * approval, refused because the type is disabled, or not describable (the
 * target could not be resolved).
 */
final readonly class ManualActionOutcome
{
    public const string STARTED = 'started';

    public const string QUEUED = 'queued';

    public const string DISABLED = 'disabled';

    public const string UNDESCRIBABLE = 'undescribable';

    public function __construct(
        public string $state,
        public ?ActionRequest $actionRequest = null,
    ) {}

    public function dispatched(): bool
    {
        return $this->actionRequest instanceof ActionRequest;
    }

    /**
     * @return array{type: string, message: string}
     */
    public function toast(string $startedMessage): array
    {
        return match ($this->state) {
            self::STARTED => ['type' => 'success', 'message' => $startedMessage],
            self::QUEUED => ['type' => 'info', 'message' => __('Queued for approval in the Action Queue.')],
            self::DISABLED => ['type' => 'error', 'message' => __('This action is disabled in Action Rules.')],
            default => ['type' => 'error', 'message' => __('That item could not be found — refresh and try again.')],
        };
    }

    /**
     * @return array{state: string, action_request_id: int|null}
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'action_request_id' => $this->actionRequest?->id];
    }
}
