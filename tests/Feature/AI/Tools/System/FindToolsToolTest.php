<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Routing\ChatToolRunContext;
use App\Ai\Routing\RoutedToolGroups;
use App\Ai\Routing\ToolGroupSource;
use App\Ai\Tools\System\FindToolsTool;
use Laravel\Ai\Tools\Request;

/**
 * @return array<string, mixed>
 */
function findTools(string $need): array
{
    return json_decode(resolve(FindToolsTool::class)->handle(new Request(['need' => $need])), true);
}

test('marks loaded groups and hints at RequestToolsTool for the rest', function (): void {
    resolve(ChatToolRunContext::class)->routed = new RoutedToolGroups(['playback' => ToolGroupSource::Picked]);

    $result = findTools('what is playing now and subtitles for a file');
    $byGroup = collect($result['matches'])->keyBy('group');

    expect($byGroup['playback']['loaded'])->toBeTrue()
        ->and($byGroup['subtitles_replacement']['loaded'])->toBeFalse()
        ->and($result['hint'])->toContain('RequestToolsTool');
});

test('every group counts as loaded when routing did not narrow the toolset', function (): void {
    $result = findTools('what is playing now');

    expect($result['matches'][0]['loaded'])->toBeTrue()
        ->and($result['hint'])->not->toContain('RequestToolsTool');
});

test('says plainly when nothing matches', function (): void {
    $result = findTools('bake a cake');

    expect($result['matches'])->toBe([])
        ->and($result['hint'])->toContain('No tool group covers this');
});

test('rejects a missing or overlong need', function (string $need): void {
    expect(findTools($need)['error'])->toBe('invalid_arguments');
})->with(['empty' => '', 'too long' => str_repeat('a', 301)]);

test('risk is Read', function (): void {
    expect(resolve(FindToolsTool::class)->risk())->toBe(Risk::Read);
});
