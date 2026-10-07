<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Concerns\ActsAsStructuredSubAgent;
use App\Ai\Routing\ToolGroup;
use App\Ai\Tools\Arr\InspectMediaFileTool;
use App\Ai\Tools\Arr\ReplaceMediaFileTool;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\TextDelta;
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

    $agentResponse = (new MediaAgent)->prompt('why is my download stuck?');

    expect($agentResponse->text)->toBe('It is not an upgrade; I can remove it.')
        ->and($agentResponse->toolResults->first()->result)->toContain('"recommendation":"remove"');

    StuckDownloadInvestigatorAgent::assertPromptedTimes(1);
    expect(AiUsageRecord::where('parent_invocation_id', $agentResponse->invocationId)->sole()->agent_class)->toBe(StuckDownloadInvestigatorAgent::class);
});

test('sub-agents use their own task selections', function (): void {
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openai', 'gpt-5.4-nano')->create();
    AiTaskModel::factory()->task(AiTask::StuckDownloadInvestigator)->selecting('openai', 'gpt-5.4-nano')->create();

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

test('both structured sub-agents take streaming and middleware from one concern', function (string $agentClass): void {
    expect(class_uses($agentClass))->toHaveKey(ActsAsStructuredSubAgent::class)
        ->and(new ReflectionMethod($agentClass, 'stream')->getFileName())
        ->toEndWith('app/Ai/Concerns/ActsAsStructuredSubAgent.php')
        ->and(new ReflectionMethod($agentClass, 'middleware')->getFileName())
        ->toEndWith('app/Ai/Concerns/ActsAsStructuredSubAgent.php');
})->with([StuckDownloadInvestigatorAgent::class, MediaFileInspectorAgent::class]);

test('a structured sub-agent streamed on its own answers with its findings as one text delta', function (): void {
    MediaFileInspectorAgent::fake([[
        'service' => 'sonarr', 'target' => 'series 42 S01E01', 'ambiguous' => false, 'choices' => [],
        'affected_files' => ['/tv/show.mkv'], 'subtitle_tracks' => ['eng'], 'candidate_fingerprints' => ['fp1'],
        'candidates' => ['1080p WEB'], 'automatic_candidate' => 'fp1',
    ]]);

    $stream = (new MediaFileInspectorAgent)->stream('Inspect Show S01E01 on sonarr');
    $events = iterator_to_array($stream, false);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TextDelta::class)
        ->and(json_decode($events[0]->delta, true))->toMatchArray(['service' => 'sonarr', 'automatic_candidate' => 'fp1'])
        ->and((string) $stream->text)->toBe($events[0]->delta);

    MediaFileInspectorAgent::assertPromptedTimes(1);
});

test('the stuck-download investigator only offers the services its tools support', function (): void {
    $stuckDownloadInvestigatorAgent = new StuckDownloadInvestigatorAgent;
    $schema = $stuckDownloadInvestigatorAgent->schema(new JsonSchemaTypeFactory);

    expect($stuckDownloadInvestigatorAgent->description())->not->toContain('Whisparr')
        ->and($schema['service']->toArray()['enum'])->toBe(['sonarr', 'radarr']);
});
