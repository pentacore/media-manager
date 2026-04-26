<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('demo:fake-actions
    {--delay=2 : Seconds between actions so the UI has time to render}
    {--count=4 : How many fake actions to create across various states}')]
#[Description('Create fake ActionRequests in various states to demo the realtime Actions page.')]
class DemoFakeActions extends Command
{
    private bool $reverbWarned = false;

    public function handle(): int
    {
        $delaySeconds = max(0, (int) $this->option('delay'));
        $count = max(1, (int) $this->option('count'));

        $scenarios = collect($this->scenarios())->take($count);

        foreach ($scenarios as $scenario) {
            $this->createAction($scenario);

            if ($delaySeconds > 0 && $scenario !== $scenarios->last()) {
                sleep($delaySeconds);
            }
        }

        $this->info('Done. Watch the Action Requests page (and per-user toast notifications).');

        return self::SUCCESS;
    }

    /**
     * @param  array{type: string, source: string, target: string, requires_approval: bool, status: ActionRequestStatus, payload: array<string, mixed>, result?: array<string, mixed>}  $scenario
     */
    private function createAction(array $scenario): void
    {
        $action = ActionRequest::create([
            'webhook_event_id' => null,
            'type' => $scenario['type'],
            'source_service' => $scenario['source'],
            'target_service' => $scenario['target'],
            'status' => $scenario['status'],
            'requires_approval' => $scenario['requires_approval'],
            'approved_by' => null,
            'payload' => $scenario['payload'],
            'result' => $scenario['result'] ?? null,
        ]);

        try {
            event(new ActionRequestCreated($action));

            if ($scenario['status'] !== ActionRequestStatus::Pending) {
                event(new ActionRequestStatusChanged($action));
            }
        } catch (BroadcastException $e) {
            $this->warnReverbOffline($e);
        } catch (Throwable $e) {
            $this->line(sprintf('  [%s → %s] %s — failed: %s',
                $scenario['source'], $scenario['target'], $scenario['type'], $e->getMessage()));

            return;
        }

        $this->line(sprintf('  [%s] %s → %s (%s)',
            $scenario['status']->value, $scenario['source'], $scenario['target'], $scenario['type']));
    }

    private function warnReverbOffline(BroadcastException $e): void
    {
        if ($this->reverbWarned) {
            return;
        }

        $this->reverbWarned = true;
        $this->newLine();
        $this->warn('Broadcast failed — Reverb is not reachable.');
        $this->warn('Start it with `vendor/bin/sail composer run dev` (boots app, queue, reverb, vite).');
        $this->line(sprintf('  underlying error: %s', $e->getMessage()));
        $this->newLine();
    }

    /**
     * @return list<array{type: string, source: string, target: string, requires_approval: bool, status: ActionRequestStatus, payload: array<string, mixed>, result?: array<string, mixed>}>
     */
    private function scenarios(): array
    {
        return [
            [
                'type' => 'delete_series',
                'source' => 'emby',
                'target' => 'sonarr',
                'requires_approval' => true,
                'status' => ActionRequestStatus::Pending,
                'payload' => ['sonarr_series_id' => 42, 'delete_files' => true],
            ],
            [
                'type' => 'delete_movie',
                'source' => 'emby',
                'target' => 'radarr',
                'requires_approval' => true,
                'status' => ActionRequestStatus::Pending,
                'payload' => ['radarr_movie_id' => 200, 'delete_files' => true],
            ],
            [
                'type' => 'emby_library_scan',
                'source' => 'sonarr',
                'target' => 'emby',
                'requires_approval' => false,
                'status' => ActionRequestStatus::Completed,
                'payload' => ['trigger' => 'sonarr_download', 'series_title' => 'Demo Show'],
                'result' => ['success' => true, 'reason' => 'scan_dispatched'],
            ],
            [
                'type' => 'cleanup_seerr_request',
                'source' => 'emby',
                'target' => 'seerr',
                'requires_approval' => false,
                'status' => ActionRequestStatus::Failed,
                'payload' => ['request_id' => 5099],
                'result' => ['success' => false, 'reason' => 'execution_failed'],
            ],
            [
                'type' => 'delete_series',
                'source' => 'emby',
                'target' => 'sonarr',
                'requires_approval' => true,
                'status' => ActionRequestStatus::Rejected,
                'payload' => ['sonarr_series_id' => 99, 'delete_files' => false],
            ],
            [
                'type' => 'emby_library_scan',
                'source' => 'seerr',
                'target' => 'emby',
                'requires_approval' => false,
                'status' => ActionRequestStatus::Executing,
                'payload' => ['trigger' => 'seerr_media_available', 'subject' => 'Demo Movie'],
            ],
        ];
    }
}
