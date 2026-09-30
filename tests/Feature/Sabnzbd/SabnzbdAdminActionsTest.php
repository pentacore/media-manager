<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    Cache::put(SabnzbdDownloadCounter::CACHE_KEY, ['queued' => 0, 'completed' => 0], 60);
    $this->connection = ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'sab-api-key']);
    $this->admin = User::factory()->admin()->create();
});

/**
 * @return array<string, string>
 */
function sabnzbdAdminQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

function sabnzbdAdminAccepts(): void
{
    Http::fake(['sab.local:8080/api*' => Http::response(['status' => true])]);
}

test('admins set a percentage, an absolute and no speed limit', function (string $value, string $sent, string $toast): void {
    sabnzbdAdminAccepts();

    $this->actingAs($this->admin)
        ->from(route('sabnzbd.queue.index'))
        ->post(route('sabnzbd.speed-limit.update'), ['value' => $value])
        ->assertRedirect(route('sabnzbd.queue.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', $toast);

    Http::assertSent(fn (Request $request): bool => sabnzbdAdminQuery($request)['mode'] === 'config'
        && sabnzbdAdminQuery($request)['name'] === 'speedlimit'
        && sabnzbdAdminQuery($request)['value'] === $sent);

    expect(ActivityLog::query()->where('action', 'sabnzbd.speed_limit.changed')->sole()->service_connection_id)->toBe($this->connection->id);
})->with([
    'percent' => ['50', '50', 'Speed limit set to 50%.'],
    'megabytes' => ['5m', '5M', 'Speed limit set to 5 MB/s.'],
    'kilobytes' => ['500K', '500K', 'Speed limit set to 500 KB/s.'],
    'none' => ['', '', 'Speed limit removed.'],
]);

test('speed limit values outside the allowed shapes are refused', function (string $value): void {
    $this->actingAs($this->admin)
        ->from(route('sabnzbd.queue.index'))
        ->post(route('sabnzbd.speed-limit.update'), ['value' => $value])
        ->assertSessionHasErrors('value');

    Http::assertNothingSent();
})->with(['0', '101', '5G', '0K', '50&mode=shutdown', 'fast']);

test('non-admins cannot use the admin SABnzbd actions', function (string $method, string $routeName, array $parameters): void {
    $this->actingAs(User::factory()->member()->create())
        ->call($method, route($routeName, $parameters), ['value' => '50', 'with_files' => false])
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'speed limit' => ['POST', 'sabnzbd.speed-limit.update', []],
    'history retry' => ['POST', 'sabnzbd.history.retry', ['nzoId' => 'SABnzbd_nzo_abc123']],
    'history delete' => ['DELETE', 'sabnzbd.history.destroy', ['nzoId' => 'SABnzbd_nzo_abc123']],
]);

test('retrying a history job queues it again', function (): void {
    sabnzbdAdminAccepts();

    $this->actingAs($this->admin)
        ->from(route('sabnzbd.queue.index'))
        ->post(route('sabnzbd.history.retry', ['nzoId' => 'SABnzbd_nzo_abc123']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Retry queued.');

    Http::assertSent(fn (Request $request): bool => sabnzbdAdminQuery($request)['mode'] === 'retry'
        && sabnzbdAdminQuery($request)['value'] === 'SABnzbd_nzo_abc123');

    expect(ActivityLog::query()->where('action', 'sabnzbd.history.retried')->sole()->metadata)->toBe(['nzo_id' => 'SABnzbd_nzo_abc123']);
});

test('deleting a history job with or without its files writes an activity and an audit row', function (bool $withFiles, string $toast): void {
    sabnzbdAdminAccepts();

    $this->actingAs($this->admin)
        ->from(route('sabnzbd.queue.index'))
        ->delete(route('sabnzbd.history.destroy', ['nzoId' => 'SABnzbd_nzo_abc123']), ['with_files' => $withFiles])
        ->assertSessionHas('inertia.flash_data.toast.message', $toast);

    Http::assertSent(fn (Request $request): bool => sabnzbdAdminQuery($request)['mode'] === 'history'
        && sabnzbdAdminQuery($request)['name'] === 'delete'
        && sabnzbdAdminQuery($request)['value'] === 'SABnzbd_nzo_abc123'
        && (($withFiles && (sabnzbdAdminQuery($request)['del_files'] ?? null) === '1') || (! $withFiles && ! isset(sabnzbdAdminQuery($request)['del_files']))));

    $activityLog = ActivityLog::query()->where('action', 'sabnzbd.history_deleted')->sole();

    expect($activityLog->isAudit())->toBeTrue()
        ->and($activityLog->user_id)->toBe($this->admin->id)
        ->and($activityLog->service_connection_id)->toBe($this->connection->id)
        ->and($activityLog->metadata['context'])->toBe(['nzo_id' => 'SABnzbd_nzo_abc123', 'with_files' => $withFiles])
        ->and(ActivityLog::query()->where('action', 'sabnzbd.history.deleted')->sole()->metadata['with_files'])->toBe($withFiles);
})->with([
    'entry only' => [false, 'History entry removed.'],
    'entry and files' => [true, 'History entry and files removed.'],
]);

test('a SABnzbd refusal or outage is reported and writes nothing', function (Closure $fake, string $toast): void {
    Sleep::fake();
    $fake();

    $this->actingAs($this->admin)
        ->from(route('sabnzbd.queue.index'))
        ->delete(route('sabnzbd.history.destroy', ['nzoId' => 'SABnzbd_nzo_abc123']), ['with_files' => false])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', $toast);

    expect(ActivityLog::query()->count())->toBe(0);
})->with([
    'status false' => [fn () => Http::fake(['sab.local:8080/api*' => Http::response(['status' => false, 'error' => 'not found'])]), 'SABnzbd refused the change.'],
    'client error' => [fn () => Http::fake(['sab.local:8080/api*' => Http::response('API Key Incorrect', 403)]), 'SABnzbd refused the change.'],
    'server error' => [fn () => Http::fake(['sab.local:8080/api*' => Http::response('boom', 500)]), 'SABnzbd is unreachable right now.'],
]);

test('a smuggled nzo_id is refused before SABnzbd is called', function (string $method, string $routeName): void {
    // Built from a valid URL so the test does not depend on how route()
    // treats a value that breaks the constraint.
    $validUrl = route($routeName, ['nzoId' => 'SABnzbd_nzo_placeholder']);

    foreach (['SABnzbd_nzo_x%26mode%3Dshutdown', 'NZO-9', 'SABnzbd_nzo_'] as $forgedId) {
        $this->actingAs($this->admin)
            ->call($method, str_replace('SABnzbd_nzo_placeholder', $forgedId, $validUrl), ['priority' => 1, 'with_files' => false])
            ->assertNotFound();
    }

    Http::assertNothingSent();
})->with([
    'history retry' => ['POST', 'sabnzbd.history.retry'],
    'history delete' => ['DELETE', 'sabnzbd.history.destroy'],
    'slot pause' => ['POST', 'sabnzbd.queue.slot.pause'],
    'slot resume' => ['POST', 'sabnzbd.queue.slot.resume'],
    'slot delete' => ['DELETE', 'sabnzbd.queue.slot.delete'],
    'slot priority' => ['PATCH', 'sabnzbd.queue.slot.priority'],
]);

test('deleting a queue slot is audited for the member who did it', function (): void {
    sabnzbdAdminAccepts();
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->from(route('sabnzbd.queue.index'))
        ->delete(route('sabnzbd.queue.slot.delete', ['nzoId' => 'SABnzbd_nzo_abc123']))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    $activityLog = ActivityLog::query()->where('action', 'sabnzbd.slot_deleted')->sole();

    expect($activityLog->isAudit())->toBeTrue()
        ->and($activityLog->user_id)->toBe($member->id)
        ->and($activityLog->metadata['context'])->toBe(['nzo_id' => 'SABnzbd_nzo_abc123']);
});

test('history pages fifty rows at a time from the query string', function (): void {
    Http::fake(['sab.local:8080/api*' => fn (Request $request) => sabnzbdAdminQuery($request)['mode'] === 'queue'
        ? Http::response(['queue' => ['paused' => false, 'slots' => []]])
        : Http::response(['history' => ['noofslots' => 120, 'slots' => [['nzo_id' => 'SABnzbd_nzo_p3', 'name' => 'Page three', 'status' => 'Completed']]]])]);

    $this->actingAs($this->admin)
        ->get(route('sabnzbd.queue.index', ['history_page' => 3]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('history.page', 3)
            ->where('history.page_size', 50)
            ->where('history.total', 120)
            ->where('history.slots.0.nzo_id', 'SABnzbd_nzo_p3'));

    Http::assertSent(fn (Request $request): bool => sabnzbdAdminQuery($request)['mode'] === 'history'
        && sabnzbdAdminQuery($request)['start'] === '100'
        && sabnzbdAdminQuery($request)['limit'] === '50');
});

test('queue and history payloads never carry job source urls, paths or other raw fields', function (): void {
    Http::fake(['sab.local:8080/api*' => fn (Request $request) => sabnzbdAdminQuery($request)['mode'] === 'queue'
        ? Http::response(['queue' => [
            'paused' => false, 'speedlimit' => '50', 'speedlimit_abs' => '5242880',
            'slots' => [['nzo_id' => 'SABnzbd_nzo_q1', 'filename' => 'Queued.nzb', 'url' => 'https://indexer.example/getnzb?apikey=indexer-secret', 'storage' => '/downloads/incomplete/queued']],
        ]])
        : Http::response(['history' => ['noofslots' => 1, 'slots' => [[
            'nzo_id' => 'SABnzbd_nzo_h1',
            'name' => 'Failed.Job',
            'status' => 'Failed',
            'fail_message' => 'URL Fetching failed; https://indexer.example/getnzb?id=9&apikey=indexer-secret',
            'url' => 'https://indexer.example/getnzb?id=9&apikey=indexer-secret',
            'storage' => '/downloads/complete/Failed.Job',
            'stage_log' => [['name' => 'Source', 'actions' => ['https://indexer.example/getnzb?apikey=indexer-secret']]],
        ]]]])]);

    $response = $this->actingAs($this->admin)->get(route('sabnzbd.queue.index'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('queue.speedlimit', '50')
        ->where('queue.speedlimit_abs', '5242880')
        ->missing('queue.slots.0.url')
        ->missing('queue.slots.0.storage')
        ->missing('history.slots.0.url')
        ->missing('history.slots.0.storage')
        ->missing('history.slots.0.stage_log')
        ->where('history.slots.0.fail_message', 'URL Fetching failed; https://indexer.example/getnzb?[redacted]'));

    expect($response->getContent())
        ->not->toContain('indexer-secret')
        ->not->toContain('/downloads/')
        ->not->toContain('sab-api-key');
});

test('history failure text loses absolute filesystem paths before it reaches the page', function (string $failMessage, string $shown): void {
    Http::fake(['sab.local:8080/api*' => fn (Request $request) => sabnzbdAdminQuery($request)['mode'] === 'queue'
        ? Http::response(['queue' => ['paused' => false, 'slots' => []]])
        : Http::response(['history' => ['noofslots' => 1, 'slots' => [
            ['nzo_id' => 'SABnzbd_nzo_h1', 'name' => 'Failed.Job', 'status' => 'Failed', 'fail_message' => $failMessage],
        ]]])]);

    $this->actingAs(User::factory()->member()->create())
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('history.slots.0.fail_message', $shown));
})->with([
    'posix folder' => ['Cannot create final folder /data/complete/Show', 'Cannot create final folder [path]'],
    'quoted posix file' => ['Unpacking failed, "/downloads/incomplete/Job.1/job.rar" is corrupt', 'Unpacking failed, "[path]" is corrupt'],
    'windows folder' => ['Cannot create final folder C:\Downloads\complete\Show', 'Cannot create final folder [path]'],
    'unc share' => ['Disk full on \\\\nas\media\complete', 'Disk full on [path]'],
    'url keeps its path' => ['URL Fetching failed; https://indexer.example/getnzb?id=9&apikey=x', 'URL Fetching failed; https://indexer.example/getnzb?[redacted]'],
    'plain text untouched' => ['Repair failed, not enough repair blocks (5 short) / aborted', 'Repair failed, not enough repair blocks (5 short) / aborted'],
]);
