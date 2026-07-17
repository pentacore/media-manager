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

/**
 * Seed the conversation with the controller's fallback title for the given
 * first message (truncated first message) — the job only ever replaces that
 * fallback, never a manual rename.
 */
function seedTitleConvo(string $firstMessage): string
{
    $title = (string) Str::of($firstMessage)->trim()->limit(60);

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
    $id = seedTitleConvo('Delete every unwatched horror movie older than 6 months');

    new GenerateConversationTitle($id, 'Delete every unwatched horror movie older than 6 months')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Sonarr Library Cleanup');
});

test('title job stores the structured title', function (): void {
    TitleAgent::fake([['title' => 'Sonarr Queue Cleanup']]);
    $id = seedTitleConvo('clear out my sonarr download queue');

    new GenerateConversationTitle($id, 'clear out my sonarr download queue')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Sonarr Queue Cleanup');
});

test('missing or empty structured title leaves the fallback intact', function (): void {
    TitleAgent::fake([['title' => '   ']]);
    $id = seedTitleConvo('something');

    new GenerateConversationTitle($id, 'something')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('something');
});

test('uses the configured title model', function (): void {
    TitleAgent::fake([['title' => 'Library Audit']]);
    resolve(AiSettings::class)->setTitleModel('gpt-5.4-nano-custom');
    $id = seedTitleConvo('audit my library');

    new GenerateConversationTitle($id, 'audit my library')
        ->handle(resolve(AiSettings::class));

    TitleAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'gpt-5.4-nano-custom');
});

test('auto title model resolves the provider cheapest model', function (): void {
    TitleAgent::fake([['title' => 'Library Audit']]);
    resolve(AiSettings::class)->setTitleModel('auto');
    $id = seedTitleConvo('audit my library');

    new GenerateConversationTitle($id, 'audit my library')
        ->handle(resolve(AiSettings::class));

    TitleAgent::assertPrompted(fn ($prompt): bool => $prompt->model === 'gpt-5.4-nano');
});

test('AI failure leaves the fallback title intact', function (): void {
    TitleAgent::fake(fn (): never => throw new RuntimeException('provider exploded'));
    $id = seedTitleConvo('do something interesting');

    new GenerateConversationTitle($id, 'do something interesting')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('do something interesting');
});

test('strips quotes and trailing punctuation from generated title', function (): void {
    TitleAgent::fake([['title' => '"Movie Cleanup."']]);
    $id = seedTitleConvo('something');

    new GenerateConversationTitle($id, 'something')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('Movie Cleanup');
});

test('a manual rename done while the job was queued is never clobbered', function (): void {
    TitleAgent::fake([['title' => 'Generated Title']]);
    $id = seedTitleConvo('original first message');

    // The user renames before the queued job runs.
    DB::table('agent_conversations')->where('id', $id)->update(['title' => 'My Custom Name']);

    new GenerateConversationTitle($id, 'original first message')
        ->handle(resolve(AiSettings::class));

    expect(DB::table('agent_conversations')->where('id', $id)->value('title'))
        ->toBe('My Custom Name');
});
