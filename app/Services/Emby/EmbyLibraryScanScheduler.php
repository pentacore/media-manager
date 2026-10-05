<?php

declare(strict_types=1);

namespace App\Services\Emby;

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Jobs\ExecuteDebouncedLibraryScan;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionDescription;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Actions\ActionRequestActivityLogger;
use App\Services\Actions\ManualActionDispatcher;
use App\Services\Actions\ManualActionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Coalesces the Emby library refreshes that arr/Seerr webhooks request.
 * A season pack fires one Download webhook per episode; each used to queue
 * its own full library refresh. Triggers for the same Emby connection now
 * fold into one not-yet-started request whose execution is pushed back
 * DEBOUNCE_SECONDS after the latest trigger, so one refresh runs after the
 * burst. Coalescing stops MAX_COALESCE_MINUTES after the request was created
 * so a continuous stream still refreshes regularly.
 */
final readonly class EmbyLibraryScanScheduler
{
    public const int DEBOUNCE_SECONDS = 60;

    public const int MAX_COALESCE_MINUTES = 10;

    public const int MAX_RECORDED_TRIGGERS = 20;

    public function __construct(
        private ActionOrchestrator $actionOrchestrator,
        private ActionRequestActivityLogger $actionRequestActivityLogger,
        private ManualActionDispatcher $manualActionDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $scanPayload  must carry a `trigger` string
     */
    public function schedule(string $sourceService, array $scanPayload, ActionDescription $description, WebhookEvent $webhookEvent): ?ActionRequest
    {
        $embyConnectionId = ServiceConnection::findActive(ServiceType::Emby)?->id;

        if ($embyConnectionId === null) {
            return $this->actionOrchestrator->dispatch(
                type: 'emby_library_scan',
                sourceService: $sourceService,
                targetService: 'emby',
                payload: $scanPayload,
                description: $description,
                webhookEvent: $webhookEvent,
            );
        }

        return DB::transaction(function () use ($sourceService, $scanPayload, $description, $webhookEvent, $embyConnectionId): ?ActionRequest {
            $this->lock($embyConnectionId);

            $scanAfter = now()->addSeconds(self::DEBOUNCE_SECONDS);
            $trigger = (string) ($scanPayload['trigger'] ?? $sourceService);

            $pendingScan = $this->pendingScan($embyConnectionId);

            if ($pendingScan instanceof ActionRequest) {
                $this->fold($pendingScan, $trigger, $scanAfter);

                return $pendingScan;
            }

            $actionRequest = $this->actionOrchestrator->dispatch(
                type: 'emby_library_scan',
                sourceService: $sourceService,
                targetService: 'emby',
                payload: [
                    ...$scanPayload,
                    'emby_connection_id' => $embyConnectionId,
                    'scan_after' => $scanAfter->toIso8601String(),
                    'coalesced_events' => 1,
                    'triggers' => [$trigger],
                ],
                description: $description,
                webhookEvent: $webhookEvent,
                deferExecution: true,
            );

            if ($actionRequest instanceof ActionRequest) {
                $this->wakeAfter($actionRequest, $scanAfter);
            }

            return $actionRequest;
        });
    }

    /**
     * A person clicked "Refresh library". If a scan for this Emby server is
     * still waiting (webhook-coalesced or pending approval), fold the click
     * into it and pull it forward to run now; otherwise file a fresh manual
     * scan. Both branches run under the advisory lock schedule() takes, so a
     * webhook landing between "nothing is waiting" and the fresh dispatch can
     * no longer file a second scan of its own.
     *
     * @return ActionRequest|ManualActionOutcome the request the click joined, or the fresh dispatch's outcome
     */
    public function foldOrDispatchManual(int $embyConnectionId, string $because): ActionRequest|ManualActionOutcome
    {
        return DB::transaction(function () use ($embyConnectionId, $because): ActionRequest|ManualActionOutcome {
            $this->lock($embyConnectionId);

            $pendingScan = $this->pendingScan($embyConnectionId);

            if ($pendingScan instanceof ActionRequest) {
                $this->fold($pendingScan, 'manual', now());

                return $pendingScan;
            }

            return $this->manualActionDispatcher->dispatch('emby_library_scan', ServiceType::Emby, [
                'trigger' => 'manual',
                'emby_connection_id' => $embyConnectionId,
                'coalesced_events' => 1,
                'triggers' => ['manual'],
            ], $because);
        });
    }

    /**
     * Count one more trigger on a scan that has not started, record it, move
     * scan_after to $scanAfter, log the fold and schedule the wake-up. Call
     * inside the advisory lock.
     */
    private function fold(ActionRequest $pendingScan, string $trigger, CarbonImmutable $scanAfter): void
    {
        $payload = $pendingScan->payload;
        $payload['coalesced_events'] = (int) ($payload['coalesced_events'] ?? 1) + 1;
        $payload['triggers'] = array_slice([...($payload['triggers'] ?? []), $trigger], -self::MAX_RECORDED_TRIGGERS);
        $payload['scan_after'] = $scanAfter->toIso8601String();
        $pendingScan->update(['payload' => $payload]);

        $this->actionRequestActivityLogger->coalesced($pendingScan, $trigger);
        $this->wakeAfter($pendingScan, $scanAfter);
    }

    /**
     * Serialises every fold and fresh dispatch for one Emby server until the
     * surrounding transaction ends. Call inside DB::transaction().
     */
    private function lock(int $embyConnectionId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [sprintf('emby-library-scan:%d', $embyConnectionId)]);
    }

    /**
     * The newest not-yet-started scan for this Emby server inside the
     * coalescing window, locked for update. Call inside the advisory lock.
     */
    private function pendingScan(int $embyConnectionId): ?ActionRequest
    {
        return ActionRequest::query()
            ->where('type', 'emby_library_scan')
            ->whereIn('status', [ActionRequestStatus::Pending->value, ActionRequestStatus::Approved->value])
            ->where('payload->emby_connection_id', (string) $embyConnectionId)
            ->where('created_at', '>=', now()->subMinutes(self::MAX_COALESCE_MINUTES))
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Pending requests wait for approval, which dispatches execution itself.
     */
    private function wakeAfter(ActionRequest $actionRequest, CarbonImmutable $scanAfter): void
    {
        if ($actionRequest->status !== ActionRequestStatus::Approved) {
            return;
        }

        dispatch(new ExecuteDebouncedLibraryScan($actionRequest->id))->delay($scanAfter)->afterCommit();
    }
}
