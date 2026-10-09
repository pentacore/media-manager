<?php

declare(strict_types=1);

use App\Ai\Tools\Arr\StuckImportVerdictTool;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([[
        'path' => '/dl/show.s01e01.mkv', 'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'series' => ['id' => 5], 'episodes' => [['id' => 11]], 'rejections' => [],
    ]])]);
});

test('a confident verdict comes back with the inspection and never queues anything', function (): void {
    Classification::fake([[
        'choice' => new ChoiceAnswer('import', ['import' => 0.93]),
        'blocklist' => new BooleanAnswer(0.1),
        'search_replacement' => new BooleanAnswer(0.1),
    ]]);

    $result = json_decode((new StuckImportVerdictTool)->handle(new Request(['service' => 'sonarr', 'download_id' => 'dl-1'])), true);

    expect($result)->ok->toBeTrue()->confident->toBeTrue()->recommendation->toBe('import')->total->toBe(1)
        ->and(ActionRequest::count())->toBe(0);
});

test('an unconfident verdict points the agent at the full investigation', function (): void {
    Classification::fake([[
        'choice' => new ChoiceAnswer('remove', ['remove' => 0.5]),
        'blocklist' => new BooleanAnswer(0.1),
        'search_replacement' => new BooleanAnswer(0.1),
    ]]);

    $result = json_decode((new StuckImportVerdictTool)->handle(new Request(['service' => 'sonarr', 'download_id' => 'dl-1'])), true);

    expect($result)->confident->toBeFalse()->message->toContain('InvestigateStuckDownload');
});

test('no verdict is reported plainly', function (): void {
    Classification::fake(fn () => throw new RuntimeException('down'));

    $result = json_decode((new StuckImportVerdictTool)->handle(new Request(['service' => 'sonarr', 'download_id' => 'dl-1'])), true);

    expect($result)->ok->toBeFalse()->reason->toBe('no_verdict');
});
