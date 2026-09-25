<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Routing\ToolGroup;
use App\Ai\Tools\Arr\InspectMediaFileTool;
use App\Ai\Tools\Arr\ReplaceMediaFileTool;
use App\Models\AiUsageRecord;
use App\Settings\AiSettings;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult as StreamedToolResult;

test('MediaAgent delegates investigations and keeps the destructive tools', function (): void {
    $classes = collect((new MediaAgent)->tools())->map(fn (object $tool): string => $tool::class);

    expect($classes)->toContain(StuckDownloadInvestigatorAgent::class, MediaFileInspectorAgent::class, ReplaceMediaFileTool::class)
        ->and($classes)->not->toContain(InspectMediaFileTool::class);
});

test('the investigator returns structured findings to the parent and is billed as a linked child', function (): void {
    StuckDownloadInvestigatorAgent::fake([[
        'service' => 'sonarr', 'download_id' => 'abc', 'title' => 'Show S01E01',
        'files' => ['/dl/show.mkv | mapped | not an upgrade'], 'recommendation' => 'remove',
        'blocklist' => false, 'search_replacement' => false, 'reason' => 'Existing file is better.',
    ]]);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'InvestigateStuckDownload', arguments: ['task' => 'Why is download abc stuck?']),
        'It is not an upgrade; I can remove it.',
    ]);

    $response = (new MediaAgent)->prompt('why is my download stuck?');

    expect($response->text)->toBe('It is not an upgrade; I can remove it.')
        ->and($response->toolResults->first()->result)->toContain('"recommendation":"remove"');

    StuckDownloadInvestigatorAgent::assertPromptedTimes(1);
    expect(AiUsageRecord::where('parent_invocation_id', $response->invocationId)->sole()->agent_class)->toBe(StuckDownloadInvestigatorAgent::class);
});

test('sub-agents use the sub-agent model setting', function (): void {
    resolve(AiSettings::class)->setSubAgentModel('gpt-5.4-nano');

    expect((new MediaFileInspectorAgent)->model())->toBe('gpt-5.4-nano')
        ->and((new StuckDownloadInvestigatorAgent)->model())->toBe('gpt-5.4-nano');
});

test('a sub-agent called in the previous turn keeps its tool group loaded', function (): void {
    expect(ToolGroup::forToolName('InvestigateStuckDownload'))->toBe([ToolGroup::Downloads])
        ->and(ToolGroup::forToolName('InspectMediaFile'))->toBe([ToolGroup::SubtitlesReplacement]);
});

test('a streamed turn hands the investigator structured findings to the parent', function (): void {
    StuckDownloadInvestigatorAgent::fake([[
        'service' => 'sonarr', 'download_id' => 'abc', 'title' => 'Show S01E01',
        'files' => ['/dl/show.mkv | mapped | not an upgrade'], 'recommendation' => 'remove',
        'blocklist' => false, 'search_replacement' => false, 'reason' => 'Existing file is better.',
    ]]);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'InvestigateStuckDownload', arguments: ['task' => 'Why is download abc stuck?']),
        'It is not an upgrade; I can remove it.',
    ]);

    $stream = (new MediaAgent)->stream('why is my download stuck?');
    $events = iterator_to_array($stream, false);

    $toolResult = collect($events)->first(fn (object $event): bool => $event instanceof StreamedToolResult && ! $event->preliminary);

    expect($stream->text)->toBe('It is not an upgrade; I can remove it.')
        ->and($toolResult)->toBeInstanceOf(StreamedToolResult::class)
        ->and((string) $toolResult->toolResult->result)->toContain('"recommendation":"remove"')
        ->not->toContain('Agent failed');

    StuckDownloadInvestigatorAgent::assertPromptedTimes(1);
    expect(AiUsageRecord::where('parent_invocation_id', $stream->invocationId)->sole()->agent_class)->toBe(StuckDownloadInvestigatorAgent::class);
});

test('a streamed turn hands the media file inspector structured findings to the parent', function (): void {
    MediaFileInspectorAgent::fake([[
        'service' => 'sonarr', 'target' => 'series 42 S01E01', 'ambiguous' => false, 'choices' => [],
        'affected_files' => ['/tv/show.mkv'], 'subtitle_tracks' => ['eng'], 'candidate_fingerprints' => ['fp1'],
        'candidates' => ['1080p WEB'], 'automatic_candidate' => 'fp1',
    ]]);
    MediaAgent::fake([
        new ToolCall(id: 'c1', name: 'InspectMediaFile', arguments: ['task' => 'Inspect Show S01E01 on sonarr']),
        'I found one replacement.',
    ]);

    $stream = (new MediaAgent)->stream('replace show s01e01');
    $events = iterator_to_array($stream, false);

    $toolResult = collect($events)->first(fn (object $event): bool => $event instanceof StreamedToolResult && ! $event->preliminary);

    expect((string) $toolResult->toolResult->result)->toContain('"automatic_candidate":"fp1"');
});
