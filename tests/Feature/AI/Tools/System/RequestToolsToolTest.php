<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Routing\ChatToolRouter;
use App\Ai\Routing\ChatToolRunContext;
use App\Ai\Routing\RoutedToolGroups;
use App\Ai\Routing\ToolGroupSource;
use App\Ai\Tools\System\RequestToolsTool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function requestTools(array $arguments): array
{
    return json_decode(resolve(RequestToolsTool::class)->handle(new Request($arguments)), true);
}

test('is read-only and named the way the router expects', function (): void {
    expect(ToolNameResolver::resolve(new RequestToolsTool))->toBe(ChatToolRouter::TOOL_REQUEST_NAME)
        ->and((new RequestToolsTool)->risk())->toBe(Risk::Read);
});

test('requests an unloaded group with a reason', function (): void {
    resolve(ChatToolRunContext::class)->routed = new RoutedToolGroups(['playback' => ToolGroupSource::Picked]);

    $result = requestTools(['groups' => ['subtitles_replacement'], 'reason' => 'Needs to inspect the file']);

    expect($result['tool_request'])->toBe([
        'groups' => [['group' => 'subtitles_replacement', 'label' => 'Subtitles & replacement']],
        'reason' => 'Needs to inspect the file',
    ])->and($result['instruction'])->toContain('End your turn');
});

test('refuses a group that is already loaded', function (): void {
    resolve(ChatToolRunContext::class)->routed = new RoutedToolGroups(['playback' => ToolGroupSource::Picked]);

    $result = requestTools(['groups' => ['playback', 'subtitles_replacement'], 'reason' => 'x']);

    expect($result['error'])->toBe('already_loaded')
        ->and($result)->not->toHaveKey('tool_request');
});

test('refuses when every tool is loaded', function (): void {
    resolve(ChatToolRunContext::class)->routingActive = true;

    expect(requestTools(['groups' => ['playback'], 'reason' => 'x'])['error'])->toBe('already_loaded');
});

test('refuses an unavailable group', function (): void {
    resolve(ChatToolRunContext::class)->routed = new RoutedToolGroups(['playback' => ToolGroupSource::Picked]);

    $result = requestTools(['groups' => ['indexers'], 'reason' => 'x']);

    expect($result['error'])->toBe('not_available');
});

test('rejects more than three groups', function (): void {
    $result = requestTools(['groups' => ['playback', 'requests', 'downloads', 'library_changes'], 'reason' => 'x']);

    expect($result['error'])->toBe('invalid_arguments');
});

test('requires a reason', function (): void {
    expect(requestTools(['groups' => ['playback']])['error'])->toBe('invalid_arguments');
});
