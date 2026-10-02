<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\ExecuteDebouncedLibraryScan;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionDescriber;
use App\Services\Emby\EmbyLibraryScanScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);

    $this->emby = ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
});

function libraryRefreshRuleRequiresApproval(bool $requiresApproval = false, bool $enabled = true): void
{
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => $requiresApproval,
        'is_enabled' => $enabled,
    ]);
}

function libraryRefreshWebhookScan(): ?ActionRequest
{
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $scanPayload = ['trigger' => 'sonarr_download', 'series_title' => 'Show'];

    return resolve(EmbyLibraryScanScheduler::class)->schedule(
        sourceService: 'sonarr',
        scanPayload: $scanPayload,
        description: resolve(ActionDescriber::class)->describe('emby_library_scan', $scanPayload)->because('Sonarr imported "Show".'),
        webhookEvent: WebhookEvent::factory()->create(['service_connection_id' => $sonarr->id]),
    );
}

test('an admin refresh dispatches a pinned manual library scan that starts now', function (): void {
    libraryRefreshRuleRequiresApproval();
    $admin = User::factory()->admin()->create(['name' => 'Ada']);

    $this->actingAs($admin)
        ->from(route('monitoring.now-playing'))
        ->post(route('monitoring.now-playing.refresh-library'))
        ->assertRedirect(route('monitoring.now-playing'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Library refresh queued.');

    $actionRequest = ActionRequest::query()->where('type', 'emby_library_scan')->sole();

    expect($actionRequest->origin)->toBe('manual')
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Approved)
        ->and($actionRequest->payload['emby_connection_id'])->toBe($this->emby->id)
        ->and($actionRequest->payload['triggers'])->toBe(['manual'])
        ->and($actionRequest->payload)->not->toHaveKey('scan_after');

    Queue::assertPushed(ExecuteActionRequest::class);

    expect(ActivityLog::query()->where('action', 'emby.library_refresh.requested')->sole()->metadata)
        ->toBe(['action_request_id' => $actionRequest->id, 'folded' => false]);
});

test('a refresh joins a webhook scan that is still waiting and pulls it forward', function (): void {
    libraryRefreshRuleRequiresApproval();
    $waiting = libraryRefreshWebhookScan();

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('monitoring.now-playing'))
        ->post(route('monitoring.now-playing.refresh-library'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Library refresh queued.');

    $actionRequest = ActionRequest::query()->where('type', 'emby_library_scan')->sole();

    expect($actionRequest->id)->toBe($waiting?->id)
        ->and($actionRequest->payload['triggers'])->toBe(['sonarr_download', 'manual'])
        ->and($actionRequest->payload['coalesced_events'])->toBe(2)
        ->and(CarbonImmutable::parse($actionRequest->payload['scan_after'])->lessThanOrEqualTo(now()->addSecond()))->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'emby.library_refresh.requested')->sole()->metadata['folded'])->toBeTrue();

    // One wake-up from the webhook (60 s out), one from the refresh (now).
    Queue::assertPushed(ExecuteDebouncedLibraryScan::class, 2);
});

test('joining a scan that still waits for approval says so', function (): void {
    libraryRefreshRuleRequiresApproval(requiresApproval: true);
    libraryRefreshWebhookScan();

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('monitoring.now-playing'))
        ->post(route('monitoring.now-playing.refresh-library'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'info')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Queued for approval in the Action Queue.');

    expect(ActionRequest::query()->where('type', 'emby_library_scan')->count())->toBe(1);
});

test('a disabled scan rule is reported and nothing is queued', function (): void {
    libraryRefreshRuleRequiresApproval(enabled: false);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('monitoring.now-playing'))
        ->post(route('monitoring.now-playing.refresh-library'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'This action is disabled in Action Rules.');

    expect(ActionRequest::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'emby.library_refresh.requested')->exists())->toBeFalse();
});

test('library refresh is admin-only', function (): void {
    libraryRefreshRuleRequiresApproval();

    foreach ([User::factory()->create(), User::factory()->member()->create()] as $user) {
        $this->actingAs($user)->post(route('monitoring.now-playing.refresh-library'))->assertForbidden();
    }

    expect(ActionRequest::query()->count())->toBe(0);
});

test('a refresh that joins a waiting scan logs the fold on that request', function (): void {
    libraryRefreshRuleRequiresApproval();
    $waiting = libraryRefreshWebhookScan();

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('monitoring.now-playing'))
        ->post(route('monitoring.now-playing.refresh-library'))
        ->assertRedirect(route('monitoring.now-playing'));

    expect(ActivityLog::query()->where('subject_id', $waiting?->id)->where('action', 'action_request.coalesced')->sole()->metadata)
        ->toMatchArray(['trigger' => 'manual', 'coalesced_events' => 2]);
});

test('without an active Emby connection the refresh is refused', function (): void {
    libraryRefreshRuleRequiresApproval();
    $this->emby->update(['is_active' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('monitoring.now-playing'))
        ->post(route('monitoring.now-playing.refresh-library'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'No active Emby connection configured.');

    expect(ActionRequest::query()->count())->toBe(0);
});
