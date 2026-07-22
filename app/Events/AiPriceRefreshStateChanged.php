<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;

class AiPriceRefreshStateChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public const string STATE_QUEUED = 'queued';

    public const string STATE_RUNNING = 'running';

    public const string STATE_SUCCEEDED = 'succeeded';

    public const string STATE_FAILED = 'failed';

    public CarbonImmutable $occurredAt;

    public function __construct(
        public string $state,
        public ?User $triggeredBy = null,
        public ?string $summary = null,
        public ?string $error = null,
        public ?int $added = null,
        public ?int $total = null,
        public ?RefreshReport $report = null,
    ) {
        $this->occurredAt = Date::now();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.ai-prices');
    }

    public function broadcastAs(): string
    {
        return 'AiPriceRefreshStateChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'state' => $this->state,
            'triggered_by' => $this->triggeredBy instanceof User ? [
                'id' => $this->triggeredBy->id,
                'name' => $this->triggeredBy->name,
            ] : null,
            'summary' => $this->summary,
            'error' => $this->error,
            'added' => $this->added,
            'total' => $this->total,
            'occurred_at' => $this->occurredAt->toISOString(),
            ...$this->report instanceof RefreshReport ? $this->report->toBroadcastArray() : self::emptyReportPayload(),
        ];
    }

    /**
     * Null-valued report keys so the broadcast contract is stable whether or
     * not a {@see RefreshReport} was attached to the event.
     *
     * @return array<string, null>
     */
    private static function emptyReportPayload(): array
    {
        return [
            'run_id' => null,
            'mode' => null,
            'final_result' => null,
            'models_dev_status' => null,
            'providers_requested' => null,
            'providers_succeeded' => null,
            'providers_failed' => null,
            'models_created' => null,
            'models_updated' => null,
            'models_unchanged' => null,
            'models_locked' => null,
            'models_rejected' => null,
            'models_tiered' => null,
            'fallback_providers' => null,
            'error_message' => null,
        ];
    }
}
