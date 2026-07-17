<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Events\SabnzbdDownloadFinished;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\ServiceClientFactory;
use App\Support\UrlQueryRedactor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Description('Poll SABnzbd history for completed/failed downloads and log them.')]
#[Signature('sabnzbd:poll-history')]
class PollSabnzbdHistory extends Command
{
    public function handle(ServiceClientFactory $serviceClientFactory): int
    {
        $connections = ServiceConnection::query()
            ->where('type', ServiceType::SABnzbd)
            ->where('is_active', true)
            ->get();

        foreach ($connections as $connection) {
            $this->pollConnection($connection, $serviceClientFactory);
        }

        return self::SUCCESS;
    }

    private function pollConnection(ServiceConnection $serviceConnection, ServiceClientFactory $serviceClientFactory): void
    {
        $client = $serviceClientFactory->make($serviceConnection);

        if (! $client instanceof SabnzbdClient) {
            return;
        }

        $settings = $serviceConnection->settings ?? [];
        $cursor = (int) ($settings['last_history_unix'] ?? (Date::now()->subHours(1)->getTimestamp()));

        try {
            $payload = $client->getHistory(0, 100, sinceUnix: $cursor);
        } catch (RequestException|ConnectionException|Throwable $throwable) {
            $this->warn(sprintf('Failed to poll %s: %s', $serviceConnection->name, UrlQueryRedactor::redact($throwable->getMessage())));

            return;
        }

        $slots = $payload['slots'] ?? [];
        $newest = $cursor;

        foreach ($slots as $slot) {
            $completed = (int) ($slot['completed'] ?? 0);

            if ($completed <= $cursor) {
                continue;
            }

            // Per-slot isolation: one malformed slot must not abort the loop
            // after earlier slots were already recorded — the cursor would
            // never advance and every recorded slot would duplicate (rows +
            // broadcasts) on the next run. Skip the bad slot and keep the
            // cursor below it so it is retried next sweep.
            try {
                $this->recordSlot($serviceConnection, $slot);
                $newest = max($newest, $completed);
            } catch (Throwable $throwable) {
                Log::warning('PollSabnzbdHistory: failed to record history slot', [
                    'service_connection_id' => $serviceConnection->id,
                    'nzo_id' => $slot['nzo_id'] ?? null,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        if ($newest !== $cursor) {
            // Targeted JSON merge onto a FRESH read: the whole-settings
            // read-modify-write raced the admin connection form, which
            // rewrites `settings` and could resurrect a stale cursor (or
            // this save could drop the admin's changes).
            $serviceConnection->refresh();
            $serviceConnection->settings = [
                ...($serviceConnection->settings ?? []),
                'last_history_unix' => $newest,
            ];
            $serviceConnection->save();
        }
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function recordSlot(ServiceConnection $serviceConnection, array $slot): void
    {
        $status = (string) ($slot['status'] ?? 'Unknown');
        $name = (string) ($slot['name'] ?? '');
        $failMessage = isset($slot['fail_message']) && $slot['fail_message'] !== ''
            ? (string) $slot['fail_message']
            : null;
        $nzoId = isset($slot['nzo_id']) ? (string) $slot['nzo_id'] : null;

        $action = $status === 'Failed'
            ? 'sabnzbd.download.failed'
            : 'sabnzbd.download.completed';

        $category = $slot['category'] ?? null;
        if (is_string($category) && in_array($category, $serviceConnection->settings['hidden_categories'] ?? [], true)) {
            Log::debug('Skipping hidden category', [
                'service_connection_id' => $serviceConnection->id,
                'nzo_id' => $nzoId,
                'category' => $category,
            ]);

            return;
        }

        ActivityLog::create([
            'service_connection_id' => $serviceConnection->id,
            'action' => $action,
            'description' => $name === '' ? 'SABnzbd download '.strtolower($status) : $name,
            'metadata' => $slot,
        ]);

        event(new SabnzbdDownloadFinished($serviceConnection, $name, $status, $failMessage, $nzoId));
    }
}
