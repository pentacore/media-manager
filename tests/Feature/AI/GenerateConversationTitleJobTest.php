<?php

declare(strict_types=1);

use App\Ai\Agents\TitleAgent;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

function seedTitleConvo(string $title = 'Truncated fallback'): string
{
    $id = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => null,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('writes the generated title onto the conversation row', function (): void {
    TitleAgent::fake([['title' => 'Sonarr Library Cleanup']]);
    $id = seedTitleConvo();

    new GenerateConversationTitle($id, 'Delete every unwatched horror movie older than 6 months')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Sonarr Library Cleanup');
});

test('title job stores the structured title', function (): void {
    TitleAgent::fake([['title' => 'Sonarr Queue Cleanup']]);
    $id = seedTitleConvo();

    new GenerateConversationTitle($id, 'clear out my sonarr download queue')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Sonarr Queue Cleanup');
});

test('missing or empty structured title leaves the fallback intact', function (): void {
    TitleAgent::fake([['title' => '   ']]);
    $id = seedTitleConvo('Truncated fallback');

    new GenerateConversationTitle($id, 'something')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Truncated fallback');
});

test('uses the configured title model', function (): void {
    TitleAgent::fake([['title' => 'Library Audit']]);
    resolve(AiSettings::class)->setTitleModel('gpt-5.4-nano-custom');
    $id = seedTitleConvo();

    new GenerateConversationTitle($id, 'audit my library')
        ->handle(resolve(AiSettings::class));

    TitleAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'gpt-5.4-nano-custom');
});

test('auto title model resolves the provider cheapest model', function (): void {
    TitleAgent::fake([['title' => 'Library Audit']]);
    resolve(AiSettings::class)->setTitleModel('auto');
    $id = seedTitleConvo();

    new GenerateConversationTitle($id, 'audit my library')
        ->handle(resolve(AiSettings::class));

    TitleAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'gpt-5.4-nano');
});

test('AI failure leaves the fallback title intact', function (): void {
    TitleAgent::fake(fn (): never => throw new RuntimeException('provider exploded'));
    $id = seedTitleConvo('Truncated fallback');

    new GenerateConversationTitle($id, 'do something interesting')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Truncated fallback');
});

test('strips quotes and trailing punctuation from generated title', function (): void {
    TitleAgent::fake([['title' => '"Movie Cleanup."']]);
    $id = seedTitleConvo();

    new GenerateConversationTitle($id, 'something')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Movie Cleanup');
});
