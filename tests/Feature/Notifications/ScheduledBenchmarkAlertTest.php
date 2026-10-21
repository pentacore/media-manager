<?php

declare(strict_types=1);

use App\Models\BenchmarkRun;
use App\Models\BenchmarkSchedule;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ScheduledBenchmarkAlert;
use App\Services\Notifications\PushMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;

function regressionAlert(): ScheduledBenchmarkAlert
{
    $benchmarkSchedule = BenchmarkSchedule::factory()->create(['name' => 'Nightly decisions']);
    $benchmarkRun = BenchmarkRun::factory()->forSchedule($benchmarkSchedule)->completed()->create();

    return ScheduledBenchmarkAlert::regression($benchmarkSchedule, $benchmarkRun, [
        [
            'candidate' => ['provider' => 'openrouter', 'model' => 'free/model', 'reasoning' => 'high'],
            'paired_cases' => 10,
            'metrics' => [
                ['metric' => 'pass_rate', 'before' => 0.9, 'after' => 0.6],
                ['metric' => 'error_rate', 'before' => 0.0, 'after' => 0.3],
            ],
        ],
        [
            'candidate' => ['provider' => 'openai', 'model' => 'gpt-x', 'reasoning' => 'provider_default'],
            'paired_cases' => 10,
            'metrics' => [['metric' => 'avg_score', 'before' => 0.8, 'after' => 0.55]],
        ],
    ]);
}

test('a regression alert names each regressed model and links to the run', function (): void {
    $scheduledBenchmarkAlert = regressionAlert();
    $payload = $scheduledBenchmarkAlert->toArray(User::factory()->create());

    expect($scheduledBenchmarkAlert->kind)->toBe('regression')
        ->and($payload['service'])->toBe('system')
        ->and($payload['title'])->toBe('Benchmark regression: Nightly decisions')
        ->and($payload['message'])->toBe('openrouter/free/model (High): pass rate 90% → 60%, errors 0% → 30%; openai/gpt-x: score 80% → 55%')
        ->and($payload['link'])->toStartWith('/admin/benchmarks/runs/');
});

test('a skipped alert carries the reason and links to the schedules', function (): void {
    $benchmarkSchedule = BenchmarkSchedule::factory()->create(['name' => 'Weekly titles']);
    $payload = ScheduledBenchmarkAlert::skipped($benchmarkSchedule, 'The estimate $2.50 is over the $1.00 cap.')->toArray(User::factory()->create());

    expect($payload['title'])->toBe('Scheduled benchmark skipped: Weekly titles')
        ->and($payload['message'])->toBe('The estimate $2.50 is over the $1.00 cap.')
        ->and($payload['link'])->toBe('/admin/benchmarks/schedules');
});

test('via defaults to database and broadcast and honours preference rows', function (): void {
    $user = User::factory()->create();
    $scheduledBenchmarkAlert = regressionAlert();

    expect($scheduledBenchmarkAlert->via($user))->toEqual(['database', 'broadcast']);

    NotificationPreference::create([
        'user_id' => $user->id, 'notification_class' => ScheduledBenchmarkAlert::class, 'severity' => 'warning',
        'database' => true, 'broadcast' => false, 'mail' => false, 'ntfy' => false,
    ]);

    expect($scheduledBenchmarkAlert->via($user))->toEqual(['database']);
});

test('broadcast mirrors the payload and push is a warning with an absolute url', function (): void {
    $user = User::factory()->create();
    $scheduledBenchmarkAlert = regressionAlert();

    $broadcastMessage = $scheduledBenchmarkAlert->toBroadcast($user);
    $pushMessage = $scheduledBenchmarkAlert->toPush($user);

    expect($broadcastMessage)->toBeInstanceOf(BroadcastMessage::class)
        ->and($broadcastMessage->data)->toBe($scheduledBenchmarkAlert->toArray($user))
        ->and($pushMessage)->toBeInstanceOf(PushMessage::class)
        ->and($pushMessage->severity)->toBe('warning')
        ->and($pushMessage->url)->toBe(url($scheduledBenchmarkAlert->link));
});

test('the alert is listed on the notification preferences page', function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('catalog', fn ($catalog): bool => collect($catalog)->contains(fn ($row): bool => $row['label'] === 'Scheduled benchmark alerts')));
});
