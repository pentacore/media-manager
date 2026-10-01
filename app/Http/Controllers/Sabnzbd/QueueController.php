<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sabnzbd;

use App\Enums\SabnzbdBulkAction;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sabnzbd\BulkSabnzbdSlotsRequest;
use App\Http\Requests\Sabnzbd\ChangePriorityRequest;
use App\Http\Requests\Sabnzbd\DeleteHistoryRequest;
use App\Http\Requests\Sabnzbd\SetSpeedLimitRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Actions\BulkItemOutcome;
use App\Services\Actions\BulkRunner;
use App\Services\Audit\AuditLogger;
use App\Services\Sabnzbd\SabnzbdClient;
use App\Services\Sabnzbd\SabnzbdSlotOperator;
use App\Services\Sabnzbd\SabnzbdSlotRefused;
use App\Services\ServiceClientFactory;
use App\Support\UrlQueryRedactor;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class QueueController extends Controller
{
    /** SABnzbd history rows per page. */
    private const int HISTORY_PAGE_SIZE = 50;

    public function index(Request $request): Response
    {
        $historyPage = min(10_000, max(1, $request->integer('history_page', 1)));

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::SABnzbd);
            $client = $this->client($connection);

            $queue = $this->filterByCategory($client->getQueue(), 'cat', $connection);
            $history = $this->filterByCategory(
                $client->getHistory(start: ($historyPage - 1) * self::HISTORY_PAGE_SIZE, limit: self::HISTORY_PAGE_SIZE),
                'category',
                $connection,
            );

            return Inertia::render('Sabnzbd/Queue/Index', [
                'configured' => true,
                'connection' => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'url' => $connection->linkUrl(),
                ],
                'queue' => $this->presentQueue($queue),
                'history' => $this->presentHistory($history, $historyPage),
                'paused' => (bool) ($queue['paused'] ?? false),
                // Polls reload `error` too, so a recovered poll must clear it.
                'error' => null,
            ]);
        } catch (ModelNotFoundException) {
            return Inertia::render('Sabnzbd/Queue/Index', [
                'configured' => false,
                'connection' => null,
                'queue' => [],
                'history' => $this->presentHistory([], $historyPage),
                'paused' => false,
                'error' => null,
            ]);
        } catch (RequestException|ConnectionException) {
            return Inertia::render('Sabnzbd/Queue/Index', [
                'configured' => true,
                'connection' => null,
                'queue' => [],
                'history' => $this->presentHistory([], $historyPage),
                'paused' => false,
                'error' => 'Could not reach SABnzbd.',
            ]);
        }
    }

    public function pauseQueue(): RedirectResponse
    {
        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection): bool {
            if (! $sabnzbdClient->pauseQueue()) {
                return false;
            }

            $this->log($serviceConnection, 'sabnzbd.queue.paused', 'Paused the SABnzbd queue.');

            return true;
        }, success: 'Queue paused.', failure: 'Failed to pause queue.');
    }

    public function resumeQueue(): RedirectResponse
    {
        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection): bool {
            if (! $sabnzbdClient->resumeQueue()) {
                return false;
            }

            $this->log($serviceConnection, 'sabnzbd.queue.resumed', 'Resumed the SABnzbd queue.');

            return true;
        }, success: 'Queue resumed.', failure: 'Failed to resume queue.');
    }

    public function pauseSlot(Request $request, string $nzoId, SabnzbdSlotOperator $sabnzbdSlotOperator): RedirectResponse
    {
        return $this->runSlot(
            fn (ServiceConnection $serviceConnection) => $sabnzbdSlotOperator->pause($serviceConnection, $nzoId, $request->user()),
            success: 'Job paused.',
            failure: 'Failed to pause job.',
        );
    }

    public function resumeSlot(Request $request, string $nzoId, SabnzbdSlotOperator $sabnzbdSlotOperator): RedirectResponse
    {
        return $this->runSlot(
            fn (ServiceConnection $serviceConnection) => $sabnzbdSlotOperator->resume($serviceConnection, $nzoId, $request->user()),
            success: 'Job resumed.',
            failure: 'Failed to resume job.',
        );
    }

    public function deleteSlot(Request $request, string $nzoId, SabnzbdSlotOperator $sabnzbdSlotOperator): RedirectResponse
    {
        return $this->runSlot(
            fn (ServiceConnection $serviceConnection) => $sabnzbdSlotOperator->delete($serviceConnection, $nzoId, $request->user()),
            success: 'Job deleted.',
            failure: 'Failed to delete job.',
        );
    }

    public function bulk(BulkSabnzbdSlotsRequest $bulkSabnzbdSlotsRequest, SabnzbdSlotOperator $sabnzbdSlotOperator, BulkRunner $bulkRunner): JsonResponse
    {
        $validated = $bulkSabnzbdSlotsRequest->validated();
        $sabnzbdBulkAction = SabnzbdBulkAction::from((string) $validated['action']);

        try {
            $connection = ServiceConnection::resolveActive(ServiceType::SABnzbd);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => __('No SABnzbd connection configured.')], 422);
        }

        $user = $bulkSabnzbdSlotsRequest->user();

        // A connect-level outage costs ~10s per item (3 retries). Once SABnzbd
        // has dropped one connection, the rest of the batch is failed locally
        // instead of repeating that cost for every remaining id.
        $unreachable = false;

        $bulkSummary = $bulkRunner->run(
            $bulkSabnzbdSlotsRequest->nzoIds(),
            function (string $nzoId) use ($sabnzbdSlotOperator, $sabnzbdBulkAction, $connection, $user, &$unreachable): BulkItemOutcome {
                if ($unreachable) {
                    return BulkItemOutcome::failed(__('SABnzbd is unreachable right now.'));
                }

                try {
                    $sabnzbdSlotOperator->apply($sabnzbdBulkAction, $connection, $nzoId, $user);
                } catch (SabnzbdSlotRefused) {
                    return BulkItemOutcome::failed(__('SABnzbd refused the change.'));
                } catch (ConnectionException $connectionException) {
                    $unreachable = true;

                    return BulkItemOutcome::fromUpstreamFailure($connectionException, 'SABnzbd');
                } catch (RequestException $requestException) {
                    return BulkItemOutcome::fromUpstreamFailure($requestException, 'SABnzbd');
                }

                return BulkItemOutcome::started();
            },
            $this->slotTitles($connection),
        );

        return response()->json($bulkSummary->withToast($sabnzbdBulkAction->pastTense()));
    }

    /**
     * Unlike 7a's withClient() (catch (Throwable)), this only catches the
     * exceptions SabnzbdSlotOperator documents throwing. An unexpected error
     * (e.g. a DB failure writing the activity row) surfaces as a 500 rather
     * than a generic toast — deliberate, per .ai/rules/controllers.md.
     *
     * @param  Closure(ServiceConnection): void  $action
     */
    private function runSlot(Closure $action, string $success, string $failure): RedirectResponse
    {
        try {
            $action(ServiceConnection::resolveActive(ServiceType::SABnzbd));
            Inertia::flash('toast', ['type' => 'success', 'message' => __($success)]);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No SABnzbd connection configured.')]);
        } catch (SabnzbdSlotRefused) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('SABnzbd refused the change.')]);
        } catch (RequestException|ConnectionException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($failure)]);
        }

        return back();
    }

    /**
     * Failure-line names: the slot filenames from SABnzbd's own queue,
     * fetched once and only when a slot failed.
     *
     * @return Closure(string): string
     */
    private function slotTitles(ServiceConnection $serviceConnection): Closure
    {
        /** @var array<string, string>|null $titles */
        $titles = null;

        return function (string $nzoId) use (&$titles, $serviceConnection): string {
            if ($titles === null) {
                $titles = [];

                try {
                    $slots = $this->client($serviceConnection)->getQueue(0, BulkRunner::MAX_ITEMS)['slots'] ?? [];
                } catch (RequestException|ConnectionException) {
                    $slots = [];
                }

                foreach (is_array($slots) ? $slots : [] as $slot) {
                    if (is_array($slot) && is_string($slot['nzo_id'] ?? null) && is_string($slot['filename'] ?? null)) {
                        $titles[$slot['nzo_id']] = $slot['filename'];
                    }
                }
            }

            return $titles[$nzoId] ?? $nzoId;
        };
    }

    public function reprioritize(ChangePriorityRequest $changePriorityRequest, string $nzoId): RedirectResponse
    {
        $priority = (int) $changePriorityRequest->validated('priority');

        return $this->withClient(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($nzoId, $priority): bool {
            // SABnzbd answers a priority change with the job's new queue
            // position, not a status flag, so there is no refusal to honour.
            $sabnzbdClient->changePriority($nzoId, $priority);
            $this->log(
                $serviceConnection,
                'sabnzbd.slot.reprioritized',
                sprintf('Set slot %s priority to %d.', $nzoId, $priority),
                ['nzo_id' => $nzoId, 'priority' => $priority],
            );

            return true;
        }, success: 'Priority updated.', failure: 'Failed to change priority.');
    }

    public function setSpeedLimit(SetSpeedLimitRequest $setSpeedLimitRequest): RedirectResponse
    {
        $validated = $setSpeedLimitRequest->validated();
        $value = isset($validated['value']) ? strtoupper((string) $validated['value']) : null;
        $label = $value === null ? null : $this->speedLimitLabel($value);

        return $this->adminAction(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($value, $label): bool {
            if (! $sabnzbdClient->setSpeedLimit($value)) {
                return false;
            }

            $this->log(
                $serviceConnection,
                'sabnzbd.speed_limit.changed',
                $label === null ? 'Removed the SABnzbd speed limit.' : sprintf('Set the SABnzbd speed limit to %s.', $label),
                ['value' => $value],
            );

            return true;
        }, $label === null ? __('Speed limit removed.') : __('Speed limit set to :limit.', ['limit' => $label]));
    }

    public function retryHistory(string $nzoId): RedirectResponse
    {
        return $this->adminAction(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($nzoId): bool {
            if (! $sabnzbdClient->retryHistory($nzoId)) {
                return false;
            }

            $this->log($serviceConnection, 'sabnzbd.history.retried', sprintf('Retried history job %s.', $nzoId), ['nzo_id' => $nzoId]);

            return true;
        }, __('Retry queued.'));
    }

    public function deleteHistory(DeleteHistoryRequest $deleteHistoryRequest, string $nzoId, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $deleteHistoryRequest->validated();
        $withFiles = (bool) ($validated['with_files'] ?? false);

        return $this->adminAction(function (SabnzbdClient $sabnzbdClient, ServiceConnection $serviceConnection) use ($nzoId, $withFiles, $auditLogger): bool {
            if (! $sabnzbdClient->deleteHistory($nzoId, $withFiles)) {
                return false;
            }

            $description = $withFiles
                ? sprintf('Removed history job %s and its files.', $nzoId)
                : sprintf('Removed history job %s.', $nzoId);

            $this->log($serviceConnection, 'sabnzbd.history.deleted', $description, ['nzo_id' => $nzoId, 'with_files' => $withFiles]);
            $auditLogger->record('sabnzbd.history_deleted', $serviceConnection, $description, context: ['nzo_id' => $nzoId, 'with_files' => $withFiles]);

            return true;
        }, $withFiles ? __('History entry and files removed.') : __('History entry removed.'));
    }

    /**
     * One member SABnzbd write. Like adminAction(), the callback returns
     * whether SABnzbd accepted it (a refusal is HTTP 200 with `status:
     * false`) and writes its activity/audit rows only when it did.
     *
     * @param  Closure(SabnzbdClient, ServiceConnection): bool  $action
     */
    private function withClient(Closure $action, string $success, string $failure): RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::SABnzbd);

            if (! $action($this->client($connection), $connection)) {
                return $this->toastBack('error', __('SABnzbd refused the change.'));
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => __($success)]);
        } catch (ModelNotFoundException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No SABnzbd connection configured.')]);
        } catch (Throwable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($failure)]);
        }

        return back();
    }

    /**
     * One admin-only SABnzbd write. SABnzbd answers a refusal with HTTP 200
     * and `status: false`, so the callback returns whether it was accepted;
     * activity and audit rows are written only on success.
     *
     * @param  Closure(SabnzbdClient, ServiceConnection): bool  $action
     */
    private function adminAction(Closure $action, string $success): RedirectResponse
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::SABnzbd);
        } catch (ModelNotFoundException) {
            return $this->toastBack('error', __('No SABnzbd connection configured.'));
        }

        try {
            $accepted = $action($this->client($connection), $connection);
        } catch (ConnectionException) {
            return $this->toastBack('error', __('SABnzbd is unreachable right now.'));
        } catch (RequestException $requestException) {
            return $this->toastBack('error', $requestException->response->serverError()
                ? __('SABnzbd is unreachable right now.')
                : __('SABnzbd refused the change.'));
        }

        return $accepted
            ? $this->toastBack('success', $success)
            : $this->toastBack('error', __('SABnzbd refused the change.'));
    }

    private function toastBack(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }

    /**
     * "50" → "50%", "500K" → "500 KB/s", "5M" → "5 MB/s".
     */
    private function speedLimitLabel(string $value): string
    {
        return match (substr($value, -1)) {
            'K' => sprintf('%s KB/s', substr($value, 0, -1)),
            'M' => sprintf('%s MB/s', substr($value, 0, -1)),
            default => sprintf('%s%%', $value),
        };
    }

    /**
     * Only the fields the page renders. SABnzbd's raw queue carries job source
     * URLs (indexer links with their API key) and local paths.
     *
     * @param  array<string, mixed>  $queue
     * @return array<string, mixed>
     */
    private function presentQueue(array $queue): array
    {
        $slots = is_array($queue['slots'] ?? null) ? array_filter($queue['slots'], is_array(...)) : [];

        return [
            'paused' => (bool) ($queue['paused'] ?? false),
            'speed' => $queue['speed'] ?? null,
            'sizeleft' => $queue['sizeleft'] ?? null,
            'timeleft' => $queue['timeleft'] ?? null,
            'speedlimit' => isset($queue['speedlimit']) ? (string) $queue['speedlimit'] : null,
            'speedlimit_abs' => isset($queue['speedlimit_abs']) ? (string) $queue['speedlimit_abs'] : null,
            'noofslots' => (int) ($queue['noofslots'] ?? count($slots)),
            'slots' => array_values(array_map(static fn (array $slot): array => [
                'nzo_id' => (string) ($slot['nzo_id'] ?? ''),
                'filename' => $slot['filename'] ?? null,
                'cat' => $slot['cat'] ?? null,
                'size' => $slot['size'] ?? null,
                'sizeleft' => $slot['sizeleft'] ?? null,
                'percentage' => $slot['percentage'] ?? null,
                'timeleft' => $slot['timeleft'] ?? null,
                'status' => $slot['status'] ?? null,
                'priority' => $slot['priority'] ?? null,
            ], $slots)),
        ];
    }

    /**
     * One history page. Raw slots also carry the job's source URL, storage
     * paths and stage logs; none of that reaches the browser, and failure
     * text loses URL query strings and filesystem paths (see redactFailMessage()).
     *
     * @param  array<string, mixed>  $history
     * @return array{slots: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    private function presentHistory(array $history, int $page): array
    {
        $slots = is_array($history['slots'] ?? null) ? array_filter($history['slots'], is_array(...)) : [];

        return [
            'slots' => array_values(array_map(static fn (array $slot): array => [
                'nzo_id' => (string) ($slot['nzo_id'] ?? ''),
                'name' => $slot['name'] ?? null,
                'category' => $slot['category'] ?? null,
                'size' => $slot['size'] ?? null,
                'status' => $slot['status'] ?? null,
                'fail_message' => is_string($slot['fail_message'] ?? null) && $slot['fail_message'] !== ''
                    ? self::redactFailMessage($slot['fail_message'])
                    : null,
                'completed' => isset($slot['completed']) ? (int) $slot['completed'] : null,
            ], $slots)),
            'total' => (int) ($history['noofslots'] ?? count($slots)),
            'page' => $page,
            'page_size' => self::HISTORY_PAGE_SIZE,
        ];
    }

    /**
     * Failure text is shown to members: it can quote the source link (with
     * the indexer API key in its query string) and absolute POSIX, Windows
     * or UNC paths from the SABnzbd host, so both are replaced.
     */
    private static function redactFailMessage(string $failMessage): string
    {
        return preg_replace(
            [
                '~(?<=^|[\s"\'(\[=,;])/[^\s"\'<>/]+(?:/[^\s"\'<>]*)?~',
                '~(?<![\w\\\\])[A-Za-z]:\\\\[^\s"\'<>]*~',
                '~(?<![\w\\\\])\\\\\\\\[^\s"\'<>]+~',
            ],
            '[path]',
            UrlQueryRedactor::redact($failMessage),
        ) ?? UrlQueryRedactor::redact($failMessage);
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
