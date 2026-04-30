<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sabnzbd;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sabnzbd\ChangePriorityRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\ServiceClientFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class QueueController extends Controller
{
    public function index(): Response
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::SABnzbd);
            $client = $this->client($connection);

            $queue = $this->filterByCategory(
                $client->getQueue(),
                'cat',
                $connection,
            );
            $history = $this->filterByCategory(
                $client->getHistory(),
                'category',
                $connection,
            );

            return Inertia::render('Sabnzbd/Queue/Index', [
                'configured' => true,
                'connection' => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'url' => rtrim($connection->url, '/'),
                ],
                'queue' => $queue,
                'history' => $history,
                'paused' => (bool) ($queue['paused'] ?? false),
            ]);
        } catch (ModelNotFoundException) {
            return Inertia::render('Sabnzbd/Queue/Index', [
                'configured' => false,
                'connection' => null,
                'queue' => [],
                'history' => [],
                'paused' => false,
            ]);
        } catch (RequestException|ConnectionException|Throwable) {
            return Inertia::render('Sabnzbd/Queue/Index', [
                'configured' => true,
                'connection' => null,
                'queue' => [],
                'history' => [],
                'paused' => false,
                'error' => 'Could not reach SABnzbd.',
            ]);
        }
    }

    public function pauseQueue(): RedirectResponse
    {
        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection): void {
            $sabnzbdClient->pauseQueue();
            $this->log($serviceConnection, 'sabnzbd.queue.paused', 'Paused the SABnzbd queue.');
        }, success: 'Queue paused.', failure: 'Failed to pause queue.');
    }

    public function resumeQueue(): RedirectResponse
    {
        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection): void {
            $sabnzbdClient->resumeQueue();
            $this->log($serviceConnection, 'sabnzbd.queue.resumed', 'Resumed the SABnzbd queue.');
        }, success: 'Queue resumed.', failure: 'Failed to resume queue.');
    }

    public function pauseSlot(string $nzoId): RedirectResponse
    {
        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($nzoId): void {
            $sabnzbdClient->pauseSlot($nzoId);
            $this->log($serviceConnection, 'sabnzbd.slot.paused', sprintf('Paused slot %s.', $nzoId), ['nzo_id' => $nzoId]);
        }, success: 'Job paused.', failure: 'Failed to pause job.');
    }

    public function resumeSlot(string $nzoId): RedirectResponse
    {
        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($nzoId): void {
            $sabnzbdClient->resumeSlot($nzoId);
            $this->log($serviceConnection, 'sabnzbd.slot.resumed', sprintf('Resumed slot %s.', $nzoId), ['nzo_id' => $nzoId]);
        }, success: 'Job resumed.', failure: 'Failed to resume job.');
    }

    public function deleteSlot(string $nzoId): RedirectResponse
    {
        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($nzoId): void {
            $sabnzbdClient->deleteSlot($nzoId);
            $this->log($serviceConnection, 'sabnzbd.slot.deleted', sprintf('Deleted slot %s.', $nzoId), ['nzo_id' => $nzoId]);
        }, success: 'Job deleted.', failure: 'Failed to delete job.');
    }

    public function reprioritize(ChangePriorityRequest $changePriorityRequest, string $nzoId): RedirectResponse
    {
        $priority = (int) $changePriorityRequest->validated('priority');

        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($nzoId, $priority): void {
            $sabnzbdClient->changePriority($nzoId, $priority);
            $this->log(
                $serviceConnection,
                'sabnzbd.slot.reprioritized',
                sprintf('Set slot %s priority to %d.', $nzoId, $priority),
                ['nzo_id' => $nzoId, 'priority' => $priority],
            );
        }, success: 'Priority updated.', failure: 'Failed to change priority.');
    }

    private function withClient(callable $action, string $success, string $failure): RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::SABnzbd);
            $action($this->client($connection), $connection);
            Inertia::flash('toast', ['type' => 'success', 'message' => __($success)]);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No SABnzbd connection configured.')]);
        } catch (Throwable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($failure)]);
        }

        return back();
    }

    /**
     * Drop slots whose category matches the connection's hidden list. The
     * `noslots` / pagination totals stay as-is — they reflect what
     * SABnzbd reports — so the user-visible "X items hidden" delta is
     * implicit. Categories with no `cat`/`category` field on the slot
     * are kept (treated as uncategorised).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterByCategory(array $payload, string $field, ServiceConnection $serviceConnection): array
    {
        $hidden = $serviceConnection->settings['hidden_categories'] ?? null;
        if (! is_array($hidden) || $hidden === []) {
            return $payload;
        }

        $slots = is_array($payload['slots'] ?? null) ? $payload['slots'] : [];
        $payload['slots'] = array_values(array_filter(
            $slots,
            static function (array $slot) use ($field, $hidden): bool {
                $category = $slot[$field] ?? null;

                return ! is_string($category) || ! in_array($category, $hidden, true);
            },
        ));

        return $payload;
    }

    private function client(ServiceConnection $serviceConnection): SabnzbdClient
    {
        $client = resolve(ServiceClientFactory::class)->make($serviceConnection);

        // Narrow the union for static analysers; the factory guarantees this branch.
        return $client instanceof SabnzbdClient ? $client : throw new RuntimeException('Expected SabnzbdClient.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function log(ServiceConnection $serviceConnection, string $action, string $description, array $metadata = []): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'service_connection_id' => $serviceConnection->id,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
