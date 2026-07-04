<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Decision\InspectStuckImportTool;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;

test('media agent registers the arr queue, history, and stuck-import tools', function (): void {
    $tools = collect((new MediaAgent)->tools())->map(fn (object $tool): string => $tool::class);

    expect($tools)->toContain(GetDownloadQueueTool::class)
        ->toContain(GetDownloadHistoryTool::class)
        ->toContain(InspectStuckImportTool::class)
        ->toContain(ResolveManualImportChatTool::class)
        ->toContain(RemoveStuckDownloadChatTool::class);
});

test('media agent instructions cover the stuck-download triage flow', function (): void {
    $instructions = (string) (new MediaAgent)->instructions();

    expect($instructions)->toContain('InspectStuckImportTool')
        ->toContain('ResolveManualImportChatTool')
        ->toContain('RemoveStuckDownloadChatTool');
});
