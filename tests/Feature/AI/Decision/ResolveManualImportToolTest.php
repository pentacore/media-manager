<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionRunContext;
use App\Ai\Decision\ResolveManualImportTool;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    resolve(DecisionAgentSettings::class)->setAllowManualImport(true);
    app()->instance(DecisionRunContext::class, new DecisionRunContext(null, 3, 'sonarr'));
});

afterEach(function (): void {
    app()->forgetInstance(DecisionRunContext::class);
});

function cleanCandidate(): array
{
    return [
        'path' => '/dl/show.s01e01.mkv',
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
        'series' => ['id' => 5],
        'episodes' => [['id' => 11]],
        'rejections' => [],
    ];
}

function fakeCandidates(array $candidates): void
{
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response($candidates)]);
}

test('refuses when the manual-import capability is disabled', function (): void {
    resolve(DecisionAgentSettings::class)->setAllowManualImport(false);

    $result = json_decode((new ResolveManualImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('capability_disabled');
    expect(ActionRequest::count())->toBe(0);
});

test('an ambiguous import is forced to require approval even when the rule auto-executes', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'resolve_manual_import', 'requires_approval' => false, 'is_enabled' => true]);
    fakeCandidates([cleanCandidate(), array_replace(cleanCandidate(), ['rejections' => [['reason' => 'x']]])]);

    $result = json_decode((new ResolveManualImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1',
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['ambiguous'])->toBeTrue();
    expect($result['requires_approval'])->toBeTrue();

    $request = ActionRequest::firstWhere('type', 'resolve_manual_import');
    expect($request->origin)->toBe('agent');
    expect($request->requires_approval)->toBeTrue();
});

test('a clean import follows the auto-execute rule', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'resolve_manual_import', 'requires_approval' => false, 'is_enabled' => true]);
    fakeCandidates([cleanCandidate()]);

    $result = json_decode((new ResolveManualImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1',
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['ambiguous'])->toBeFalse();
    expect($result['requires_approval'])->toBeFalse();
});

test('reports nothing_importable when no candidate maps', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'resolve_manual_import', 'requires_approval' => true, 'is_enabled' => true]);
    fakeCandidates([]);

    $result = json_decode((new ResolveManualImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => 'dl-1',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('nothing_importable');
    expect(ActionRequest::count())->toBe(0);
});

test('rejects an invalid service', function (): void {
    $result = json_decode((new ResolveManualImportTool)->handle(new Request([
        'service' => 'emby', 'download_id' => 'dl-1',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('invalid_service');
});

test('requires a download_id', function (): void {
    $result = json_decode((new ResolveManualImportTool)->handle(new Request([
        'service' => 'sonarr', 'download_id' => '',
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('missing_download_id');
});
