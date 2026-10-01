<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Models\ActionRequest;

/**
 * What happened to a member's library action: started right away, queued for
 * approval, refused because the type is disabled, not describable (the
 * target could not be resolved), or blocked by a file replacement in flight.
 */
final readonly class ManualActionOutcome
{
    public const string STARTED = 'started';

    public const string QUEUED = 'queued';

    public const string DISABLED = 'disabled';

    public const string UNDESCRIBABLE = 'undescribable';

    /** Refused before dispatch: a file replacement is in flight for the title. */
    public const string BLOCKED = 'blocked';

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
            self::BLOCKED => ['type' => 'error', 'message' => __('A file replacement is in progress for this title — try again when it finishes.')],
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
