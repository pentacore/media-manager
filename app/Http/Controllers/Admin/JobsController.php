<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ServiceCheckBatch;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class JobsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Jobs/Index', [
            'queued' => $this->queuedJobs(),
            'failed' => $this->failedJobs(),
            'batches' => $this->batches(),
            'scheduled' => $this->scheduledCommands(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queuedJobs(): array
    {
        $rows = DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $rows->map(static function (object $row): array {
            $payload = json_decode((string) $row->payload, true);
            $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;

            return [
                'id' => (int) $row->id,
                'queue' => (string) $row->queue,
                'class' => is_string($displayName) ? $displayName : 'unknown',
                'attempts' => (int) $row->attempts,
                'reserved' => $row->reserved_at !== null,
                'available_at' => $row->available_at !== null
                    ? CarbonImmutable::createFromTimestamp($row->available_at)->toIso8601String()
                    : null,
                'created_at' => $row->created_at !== null
                    ? CarbonImmutable::createFromTimestamp($row->created_at)->toIso8601String()
                    : null,
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function failedJobs(): array
    {
        $rows = DB::table('failed_jobs')
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->latest('failed_at')
            ->limit(50)
            ->get();

        return $rows->map(static function (object $row): array {
            $payload = json_decode((string) $row->payload, true);
            $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;

            // Exception messages can be huge — keep the first line and the
            // root exception class for the at-a-glance view; the full
            // trace is still in the DB row for anyone who wants it.
            $exception = (string) $row->exception;
            $firstLine = strtok($exception, "\n");
            [$exClass] = explode(':', $firstLine ?: '', 2) + ['Throwable'];

            return [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'class' => is_string($displayName) ? $displayName : 'unknown',
                'exception_class' => trim($exClass),
                'message' => $firstLine !== false ? trim($firstLine) : '',
                'failed_at' => $row->failed_at,
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function batches(): array
    {
        $currentHealth = Cache::get(ServiceCheckBatch::CACHE_KEY_HEALTH);
        $currentVersions = Cache::get(ServiceCheckBatch::CACHE_KEY_VERSIONS);

        $rows = DB::table('job_batches')
            ->select(['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'created_at', 'finished_at', 'cancelled_at'])->latest()
            ->limit(10)
            ->get();

        return $rows->map(static function (object $row) use ($currentHealth, $currentVersions): array {
            $createdAt = $row->created_at !== null
                ? CarbonImmutable::createFromTimestamp($row->created_at)->toIso8601String()
                : null;
            $finishedAt = $row->finished_at !== null
                ? CarbonImmutable::createFromTimestamp($row->finished_at)->toIso8601String()
                : null;
            $cancelledAt = $row->cancelled_at !== null
                ? CarbonImmutable::createFromTimestamp($row->cancelled_at)->toIso8601String()
                : null;

            $status = match (true) {
                $cancelledAt !== null => 'cancelled',
                (int) $row->failed_jobs > 0 && (int) $row->pending_jobs === 0 => 'failed',
                (int) $row->pending_jobs === 0 => 'complete',
                default => 'running',
            };

            return [
                'id' => (string) $row->id,
                'name' => (string) ($row->name ?? ''),
                'total_jobs' => (int) $row->total_jobs,
                'pending_jobs' => (int) $row->pending_jobs,
                'failed_jobs' => (int) $row->failed_jobs,
                'status' => $status,
                'created_at' => $createdAt,
                'finished_at' => $finishedAt,
                'cancelled_at' => $cancelledAt,
                'is_current_health' => $currentHealth !== null && (string) $row->id === (string) $currentHealth,
                'is_current_versions' => $currentVersions !== null && (string) $row->id === (string) $currentVersions,
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduledCommands(): array
    {
        $events = resolve(Schedule::class)->events();

        return array_values(array_map(static function (Event $event): array {
            $expression = (string) ($event->expression ?? '');
            $command = (string) ($event->command ?? $event->description ?? 'unknown');

            // Strip the PHP binary prefix Laravel prepends to artisan
            // calls so the table reads cleanly.
            $command = preg_replace('/^[\'"]?[^ ]*php[\'"]?\s+[\'"]?artisan[\'"]?\s*/u', '', $command) ?? $command;

            $nextRunDate = null;
            try {
                $nextRunDate = CarbonImmutable::instance(
                    new CronExpression($expression)->getNextRunDate(),
                )->toIso8601String();
            } catch (Throwable) {
                // bad expression — leave as null.
            }

            return [
                'command' => trim($command),
                'expression' => $expression,
                'description' => (string) ($event->description ?? ''),
                'without_overlapping' => (bool) ($event->withoutOverlapping ?? false),
                'next_run' => $nextRunDate,
            ];
        }, $events));
    }
}
