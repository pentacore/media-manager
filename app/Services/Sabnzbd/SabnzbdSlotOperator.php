<?php

declare(strict_types=1);

namespace App\Services\Sabnzbd;

use App\Enums\SabnzbdBulkAction;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Pauses, resumes or deletes one SABnzbd queue slot and writes its activity
 * row (and, for deletes, its audit row). The per-slot buttons and the bulk
 * endpoint both call this, so logging and auditing are the same for one
 * slot or many. SABnzbd answers a refusal with HTTP 200 and `status: false`,
 * not an HTTP error, so a refused slot throws SabnzbdSlotRefused and writes
 * nothing.
 */
final readonly class SabnzbdSlotOperator
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @throws RequestException|ConnectionException|SabnzbdSlotRefused
     */
    public function pause(ServiceConnection $serviceConnection, string $nzoId, ?User $user): void
    {
        throw_unless(new SabnzbdClient($serviceConnection)->pauseSlot($nzoId), SabnzbdSlotRefused::class);

        $this->log($serviceConnection, $user, 'sabnzbd.slot.paused', sprintf('Paused slot %s.', $nzoId), $nzoId);
    }

    /**
     * @throws RequestException|ConnectionException|SabnzbdSlotRefused
     */
    public function resume(ServiceConnection $serviceConnection, string $nzoId, ?User $user): void
    {
        throw_unless(new SabnzbdClient($serviceConnection)->resumeSlot($nzoId), SabnzbdSlotRefused::class);

        $this->log($serviceConnection, $user, 'sabnzbd.slot.resumed', sprintf('Resumed slot %s.', $nzoId), $nzoId);
    }

    /**
     * @throws RequestException|ConnectionException|SabnzbdSlotRefused
     */
    public function delete(ServiceConnection $serviceConnection, string $nzoId, ?User $user): void
    {
        throw_unless(new SabnzbdClient($serviceConnection)->deleteSlot($nzoId), SabnzbdSlotRefused::class);

        $this->log($serviceConnection, $user, 'sabnzbd.slot.deleted', sprintf('Deleted slot %s.', $nzoId), $nzoId);

        // Subject, description and context match 7a's QueueController::deleteSlot() exactly.
        $this->auditLogger->record(
            'sabnzbd.slot_deleted',
            $serviceConnection,
            sprintf('Deleted SABnzbd queue job %s.', $nzoId),
            context: ['nzo_id' => $nzoId],
        );
    }

    /**
     * @throws RequestException|ConnectionException|SabnzbdSlotRefused
     */
    public function apply(SabnzbdBulkAction $sabnzbdBulkAction, ServiceConnection $serviceConnection, string $nzoId, ?User $user): void
    {
        match ($sabnzbdBulkAction) {
            SabnzbdBulkAction::Pause => $this->pause($serviceConnection, $nzoId, $user),
            SabnzbdBulkAction::Resume => $this->resume($serviceConnection, $nzoId, $user),
            SabnzbdBulkAction::Delete => $this->delete($serviceConnection, $nzoId, $user),
        };
    }

    private function log(ServiceConnection $serviceConnection, ?User $user, string $action, string $description, string $nzoId): void
    {
        ActivityLog::create([
            'user_id' => $user?->id,
            'service_connection_id' => $serviceConnection->id,
            'action' => $action,
            'description' => $description,
            'metadata' => ['nzo_id' => $nzoId],
        ]);
    }
}
