<?php

declare(strict_types=1);

use App\Ai\Agents\CommandAgent;
use App\Ai\Tools\CreateActionRequestTool;
use App\Ai\Tools\GetServiceStatusTool;
use App\Ai\Tools\QueryActivityTool;
use App\Ai\Tools\SearchMediaTool;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;

test('implements the required interfaces', function (): void {
    $agent = new CommandAgent;

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent)->toBeInstanceOf(Conversational::class);
    expect($agent)->toBeInstanceOf(HasTools::class);
});

test('exposes all four tools including CreateActionRequestTool', function (): void {
    $tools = iterator_to_array((function () {
        yield from (new CommandAgent)->tools();
    })());

    expect($tools)->toHaveCount(4);
    $classes = array_map(fn (Tool $tool): string => $tool::class, $tools);
    expect($classes)->toContain(SearchMediaTool::class);
    expect($classes)->toContain(GetServiceStatusTool::class);
    expect($classes)->toContain(QueryActivityTool::class);
    expect($classes)->toContain(CreateActionRequestTool::class);
});

test('instructions mention the approval workflow', function (): void {
    $text = (string) (new CommandAgent)->instructions();
    expect($text)->toContain('approval');
    expect($text)->toContain('CreateActionRequestTool');
});

test('instructions list all four supported action types', function (): void {
    $text = (string) (new CommandAgent)->instructions();
    expect($text)->toContain('delete_series');
    expect($text)->toContain('delete_movie');
    expect($text)->toContain('cleanup_seerr_request');
    expect($text)->toContain('emby_library_scan');
});

test('can be faked and prompted with a user', function (): void {
    CommandAgent::fake(['Queued delete_series action #1 (pending approval).']);

    $user = User::factory()->admin()->create();
    $agentResponse = (new CommandAgent)->forUser($user)->prompt('Delete Breaking Bad from Sonarr');

    expect($agentResponse->text)->toContain('pending');
    CommandAgent::assertPrompted('Delete Breaking Bad from Sonarr');
});
