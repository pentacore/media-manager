<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Ai\Tools\Decision\StuckImportVerdictTool;
use App\Settings\AiSettings;

test('media agent delegates stuck-download investigation and keeps the acting tools', function (): void {
    $tools = collect((new MediaAgent)->tools())->map(fn (object $tool): string => $tool::class);

    expect($tools)->toContain(StuckDownloadInvestigatorAgent::class)
        ->toContain(ResolveManualImportChatTool::class)
        ->toContain(RemoveStuckDownloadChatTool::class)
        ->not->toContain(GetDownloadQueueTool::class)
        ->not->toContain(GetDownloadHistoryTool::class)
        ->not->toContain(InspectStuckImportTool::class);
});

test('the stuck-download investigator carries the queue, history, and stuck-import tools', function (): void {
    $tools = collect((new StuckDownloadInvestigatorAgent)->tools())->map(fn (object $tool): string => $tool::class);

    expect($tools->all())->toBe([GetDownloadQueueTool::class, GetDownloadHistoryTool::class, InspectStuckImportTool::class]);
});

test('media agent instructions cover the stuck-download triage flow', function (): void {
    $instructions = (string) (new MediaAgent)->instructions();

    expect($instructions)->toContain('InvestigateStuckDownload')
        ->toContain('ResolveManualImportChatTool')
        ->toContain('RemoveStuckDownloadChatTool');
});

test('media agent offers the stuck-import verdict tool only while the fast path is on', function (): void {
    $tools = fn (): array => collect((new MediaAgent)->tools())->map(fn (object $tool): string => $tool::class)->all();

    expect($tools())->not->toContain(StuckImportVerdictTool::class);

    resolve(AiSettings::class)->setStuckImportFastPathEnabled(true);

    expect($tools())->toContain(StuckImportVerdictTool::class);
});
