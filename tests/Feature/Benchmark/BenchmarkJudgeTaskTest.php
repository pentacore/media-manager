<?php

declare(strict_types=1);

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\TaskModelResolver;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Services\Benchmark\DefaultRubrics;

beforeEach(function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');
});

test('the judge task follows the chat default pair without a row', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();

    $resolvedSelection = resolve(TaskModelResolver::class)->resolve(AiTask::BenchmarkJudge);

    expect($resolvedSelection->provider)->toBe('openai')
        ->and($resolvedSelection->model)->toBe('gpt-5.6-luna');
});

test('a saved judge row wins over the chat default', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    AiTaskModel::factory()->task(AiTask::BenchmarkJudge)->selecting('openai', 'gpt-5-nano')->create();

    expect(resolve(TaskModelResolver::class)->resolve(AiTask::BenchmarkJudge)->model)->toBe('gpt-5-nano');
});

test('the judge task inherits chat and reasons but takes no tiers', function (): void {
    expect(AiTask::BenchmarkJudge->inheritsChatModel())->toBeTrue()
        ->and(AiTask::BenchmarkJudge->hasReasoning())->toBeTrue()
        ->and(AiTask::BenchmarkJudge->label())->toBe('Benchmark judge');
});

test('the default rubrics cover the benchmarkable tasks only', function (): void {
    expect(DefaultRubrics::for(AiTask::Title))->toContain('at most 6 words')
        ->and(DefaultRubrics::for(AiTask::Chat))->toContain('advisory mode')
        ->and(DefaultRubrics::for(AiTask::FileInspector))->toContain('Accurate diagnosis')
        ->and(DefaultRubrics::for(AiTask::StuckDownloadInvestigator))->toContain('actionable');

    DefaultRubrics::for(AiTask::Decision);
})->throws(InvalidArgumentException::class);

test('the judge prompt carries the labelled sections and the case note', function (): void {
    $prompt = DefaultRubrics::judgePrompt(
        AiTask::Chat,
        'Is Dune downloaded?',
        [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'hello']],
        [['tool' => 'search', 'arguments' => ['q' => 'dune'], 'result' => 'found']],
        'Yes it is.',
        'Must mention the 4K copy',
    );

    expect($prompt)->toContain('TASK', 'RUBRIC', 'CASE NOTE', 'CONVERSATION', 'USER PROMPT', 'TOOL RESULTS', 'CANDIDATE OUTPUT')
        ->toContain('Must mention the 4K copy')
        ->toContain('Is Dune downloaded?')
        ->toContain('search({"q":"dune"}) → found')
        ->toContain('Yes it is.');
});

test('the judge prompt truncates long tool results and conversation turns and caps the lists', function (): void {
    $toolResults = array_map(
        static fn (int $i): array => ['tool' => 't'.$i, 'arguments' => [], 'result' => str_repeat('x', 5000)],
        range(1, 25),
    );
    $history = array_map(
        static fn (int $i): array => ['role' => 'user', 'content' => 'turn'.$i.str_repeat('y', 900)],
        range(1, 10),
    );

    $prompt = DefaultRubrics::judgePrompt(AiTask::Chat, 'p', $history, $toolResults, 'out', null);

    expect($prompt)->not->toContain(str_repeat('x', 2001))
        ->toContain('t20(')
        ->not->toContain('t21(')
        ->not->toContain(str_repeat('y', 501))
        ->toContain('turn10')
        ->not->toContain('turn4y')
        ->not->toContain('CASE NOTE');
});

test('the judge prompt carries no provider or model of the configured selections', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-candidate-9')->create();
    AiTaskModel::factory()->task(AiTask::BenchmarkJudge)->selecting('openai', 'gpt-judge-7')->create();

    $prompt = DefaultRubrics::judgePrompt(AiTask::Title, 'Make a title', [], [], 'A title', null);

    expect($prompt)->not->toContain('gpt-candidate-9')
        ->not->toContain('gpt-judge-7')
        ->not->toContain('openai');
});

test('the judge agent returns its structured score', function (): void {
    BenchmarkJudgeAgent::fake([['score' => 8, 'rationale' => 'Good']]);

    $structuredAgentResponse = BenchmarkJudgeAgent::make()->prompt('judge this');

    expect($structuredAgentResponse['score'])->toBe(8)
        ->and($structuredAgentResponse['rationale'])->toBe('Good')
        ->and(BenchmarkJudgeAgent::make()->aiTask())->toBe(AiTask::BenchmarkJudge);
});

test('the judge prompt lists the attachments of every turn', function (): void {
    $prompt = DefaultRubrics::judgePrompt(AiTask::Chat, 'What does this error mean?', [], [], 'A stalled import.', null, null, [
        ['label' => 'turn 1', 'name' => 'download.log', 'mime_type' => 'text/plain'],
        ['label' => 'final prompt', 'name' => 'screenshot.png', 'mime_type' => 'image/png'],
    ]);

    expect($prompt)->toContain("ATTACHMENTS\nturn 1: download.log (text/plain)\nfinal prompt: screenshot.png (image/png)");
});

test('the judge prompt has no attachments section without attachments', function (): void {
    expect(DefaultRubrics::judgePrompt(AiTask::Chat, 'p', [], [], 'out', null))->not->toContain('ATTACHMENTS');
});

test('a scripted transcript replaces the recorded conversation, keeping the last 20 turns cut to 1500 characters', function (): void {
    $transcript = [];

    foreach (range(1, 22) as $index) {
        $transcript[] = ['role' => $index % 2 === 1 ? 'user' : 'assistant', 'content' => sprintf('turn %d %s', $index, str_repeat('x', 2000)), 'attachments' => []];
    }

    $prompt = DefaultRubrics::judgePrompt(AiTask::Chat, 'Then add it.', [['role' => 'assistant', 'content' => 'RECORDED REPLY']], [], 'Queued.', null, null, [], $transcript);

    expect($prompt)->toContain('CONVERSATION (candidate transcript, last 20 turns)')
        ->toContain('Judge the whole conversation: does each reply follow from the earlier turns, stay consistent with what the assistant said before, and answer the last message?')
        ->toContain('user: turn 3 ')
        ->not->toContain('turn 1 x')
        ->not->toContain('turn 2 x')
        ->not->toContain('RECORDED REPLY')
        ->not->toContain(str_repeat('x', 1500));
});
