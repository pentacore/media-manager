<?php

declare(strict_types=1);

namespace App\Services\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * What happened to one item of a bulk action. Built by the surface's
 * single-item closure: from the Action Queue outcome, from an upstream
 * failure, or directly (skipped: nothing left to do for that item).
 */
final readonly class BulkItemOutcome
{
    public const string STARTED = 'started';

    public const string QUEUED = 'queued';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    private function __construct(
        public string $state,
        public ?string $reason = null,
    ) {}

    public static function started(): self
    {
        return new self(self::STARTED);
    }

    public static function queued(): self
    {
        return new self(self::QUEUED);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, $reason);
    }

    /**
     * The Action Queue outcome of one item, worded exactly like the single
     * action's toast when it did not go through.
     */
    public static function fromManualAction(ManualActionOutcome $manualActionOutcome): self
    {
        return match ($manualActionOutcome->state) {
            ManualActionOutcome::STARTED => self::started(),
            ManualActionOutcome::QUEUED => self::queued(),
            default => self::failed($manualActionOutcome->toast('')['message']),
        };
    }

    /**
     * Transport errors and 5xx read as an outage, 4xx as a refusal. The
     * upstream body and URL are never echoed.
     */
    public static function fromUpstreamFailure(RequestException|ConnectionException $exception, string $service): self
    {
        return $exception instanceof RequestException && $exception->response->clientError()
            ? self::failed(__(':service refused the change.', ['service' => $service]))
            : self::failed(__(':service is unreachable right now.', ['service' => $service]));
    }

    /**
     * @return array{state: string, reason: string|null}
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'reason' => $this->reason];
    }
}
