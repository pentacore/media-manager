<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Routing\ChatToolRunContext;
use App\Ai\Routing\RoutedToolGroups;
use App\Ai\Routing\ToolGroupSource;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Ai\Tools\Arr\StuckImportVerdictTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Ai\Tools\System\FindToolsTool;
use App\Ai\Tools\System\RequestToolsTool;
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

test('both stuck-download prompts cap an inspection at 20 download ids per service', function (): void {
    $investigator = (new StuckDownloadInvestigatorAgent)->instructions();
    $mediaAgent = (string) (new MediaAgent)->instructions();

    expect($investigator)->toContain('at most 20 download_ids per call')
        ->toContain('calls of up to 20 per service')
        ->toContain('Never call it once per download')
        ->and($mediaAgent)->toContain('at most 20 download_ids per call')
        ->toContain('calls of up to 20 per service')
        ->toContain('never call it once per download');
});

test('find and request tools are declared only on routed turns', function (): void {
    $tools = fn (): array => collect((new MediaAgent)->tools())->map(fn (object $tool): string => $tool::class)->all();

    resolve(AiSettings::class)->setChatRoutingEnabled(true);

    expect($tools())->not->toContain(FindToolsTool::class)->not->toContain(RequestToolsTool::class);

    $chatToolRunContext = resolve(ChatToolRunContext::class);
    $chatToolRunContext->routingActive = true;

    expect($tools())->not->toContain(FindToolsTool::class)->not->toContain(RequestToolsTool::class);

    $chatToolRunContext->routed = new RoutedToolGroups(['playback' => ToolGroupSource::Picked]);

    expect($tools())->toContain(FindToolsTool::class)->toContain(RequestToolsTool::class);
});

test('the routing instructions appear only on routed turns', function (): void {
    resolve(AiSettings::class)->setChatRoutingEnabled(true);

    expect((string) (new MediaAgent)->instructions())->not->toContain('FindToolsTool');

    resolve(ChatToolRunContext::class)->routed = new RoutedToolGroups(['playback' => ToolGroupSource::Picked]);

    expect((string) (new MediaAgent)->instructions())->toContain('FindToolsTool')->toContain('RequestToolsTool');
});
