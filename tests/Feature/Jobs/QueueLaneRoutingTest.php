<?php

declare(strict_types=1);

use App\Enums\QueueLane;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Jobs\BroadcastDashboardStats;
use App\Jobs\ClearSeerrRequests;
use App\Jobs\EmbedLibraryItem;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\ExecuteDebouncedLibraryScan;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\PruneSubtitleUploads;
use App\Jobs\ReconcileBazarrConnection;
use App\Jobs\ReconcileSearchIndex;
use App\Jobs\RefreshAiPricesJob;
use App\Jobs\RunDecisionAgent;
use App\Jobs\RunSubtitleAdvisor;
use App\Jobs\SyncAnimeMappingJob;
use App\Models\ActionRequest;
use App\Models\IndexedMovie;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Finder\Finder;

test('each job is pushed onto its lane', function (Closure $makeJob, QueueLane $queueLane): void {
    Queue::fake();

    $job = $makeJob();
    dispatch($job);

    Queue::assertPushedOn($queueLane, $job::class);
})->with([
    'approved actions' => [fn (): ExecuteActionRequest => new ExecuteActionRequest(ActionRequest::factory()->create()), QueueLane::Actions],
    'debounced library scan wake-up' => [fn (): ExecuteDebouncedLibraryScan => new ExecuteDebouncedLibraryScan(1), QueueLane::Actions],
    'inbound webhooks' => [fn (): ProcessWebhookEvent => new ProcessWebhookEvent(WebhookEvent::factory()->create()), QueueLane::Webhooks],
    'decision agent' => [fn (): RunDecisionAgent => new RunDecisionAgent(null, 'sonarr', 'Download', ['series' => ['id' => 1]]), QueueLane::Ai],
    'subtitle advisor' => [fn (): RunSubtitleAdvisor => new RunSubtitleAdvisor(1), QueueLane::Ai],
    'price refresh' => [fn (): RefreshAiPricesJob => new RefreshAiPricesJob(User::factory()->admin()->create()), QueueLane::Ai],
    'library embedding' => [fn (): EmbedLibraryItem => new EmbedLibraryItem(IndexedMovie::class, 1), QueueLane::Ai],
    'conversation title' => [fn (): GenerateConversationTitle => new GenerateConversationTitle('01J9ZZZZZZZZZZZZZZZZZZZZZZ', 'hello'), QueueLane::Ai],
    'bulk seerr request clear' => [fn (): ClearSeerrRequests => new ClearSeerrRequests(1, 'pending', [1, 2, 3], null), QueueLane::Maintenance],
    'search index reconcile' => [fn (): ReconcileSearchIndex => new ReconcileSearchIndex, QueueLane::Maintenance],
    'anime mapping sync' => [fn (): SyncAnimeMappingJob => new SyncAnimeMappingJob, QueueLane::Maintenance],
    'subtitle upload prune' => [fn (): PruneSubtitleUploads => new PruneSubtitleUploads, QueueLane::Maintenance],
]);

test('jobs that neither execute actions, process webhooks nor call a model stay on the default lane', function (string $jobClass): void {
    expect(new ReflectionClass($jobClass)->getAttributes(QueueAttribute::class))->toBe([]);
})->with([
    'dashboard stats rebroadcast' => [BroadcastDashboardStats::class],
    'bazarr connection reconcile (a user waits on it after a Bazarr webhook)' => [ReconcileBazarrConnection::class],
]);

test('every queued job either stays on the default lane or names a known lane', function (): void {
    $unknownLanes = collect(Finder::create()->files()->in(app_path('Jobs'))->name('*.php'))
        ->map(fn (SplFileInfo $file): string => 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class): bool => is_subclass_of($class, ShouldQueue::class))
        ->mapWithKeys(fn (string $class): array => [$class => new ReflectionClass($class)->getAttributes(QueueAttribute::class)[0] ?? null])
        ->filter()
        ->map(fn (ReflectionAttribute $reflectionAttribute): string => $reflectionAttribute->newInstance()->queue)
        ->reject(fn (string $queue): bool => in_array($queue, QueueLane::values(), true))
        ->all();

    expect($unknownLanes)->toBe([]);
});

test('the general worker lanes are every lane except the ai and maintenance lanes, in priority order', function (): void {
    expect(QueueLane::values())->toBe(['actions', 'webhooks', 'default', 'ai', 'maintenance'])
        ->and(QueueLane::generalLanes())->toBe([QueueLane::Actions, QueueLane::Webhooks, QueueLane::Default])
        ->and(QueueLane::Ai->hasDedicatedWorker())->toBeTrue()
        ->and(QueueLane::Maintenance->hasDedicatedWorker())->toBeTrue();
});
