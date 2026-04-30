<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Events\SabnzbdDownloadFinished;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\ServiceClientFactory;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class PollSabnzbdHistory extends Command
{
    #[Override]
    protected $signature = 'sabnzbd:poll-history';

    #[Override]
    protected $description = 'Poll SABnzbd history for completed/failed downloads and log them.';

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
            $this->warn(sprintf('Failed to poll %s: %s', $serviceConnection->name, $throwable->getMessage()));

            return;
        }

        $slots = $payload['slots'] ?? [];
        $newest = $cursor;

        foreach ($slots as $slot) {
            $completed = (int) ($slot['completed'] ?? 0);

            if ($completed <= $cursor) {
                continue;
            }

            $newest = max($newest, $completed);
            $this->recordSlot($serviceConnection, $slot);
        }

        if ($newest !== $cursor) {
            $settings['last_history_unix'] = $newest;
            $serviceConnection->settings = $settings;
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
