<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAdvisorAgent;
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
    $agent = new MediaAdvisorAgent;

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent)->toBeInstanceOf(Conversational::class);
    expect($agent)->toBeInstanceOf(HasTools::class);
});

test('exposes the three read-only tools', function (): void {
    $tools = iterator_to_array((function () {
        yield from (new MediaAdvisorAgent)->tools();
    })());

    expect($tools)->toHaveCount(3);
    $classes = array_map(fn (Tool $tool): string => $tool::class, $tools);
    expect($classes)->toContain(SearchMediaTool::class);
    expect($classes)->toContain(QueryActivityTool::class);
    expect($classes)->toContain(GetServiceStatusTool::class);
});

test('does NOT expose CreateActionRequestTool', function (): void {
    $tools = iterator_to_array((function () {
        yield from (new MediaAdvisorAgent)->tools();
    })());

    $classes = array_map(fn (Tool $tool): string => $tool::class, $tools);
    expect($classes)->not->toContain(CreateActionRequestTool::class);
});

test('instructions mention MediaAdvisor role', function (): void {
    $text = (string) (new MediaAdvisorAgent)->instructions();
    expect($text)->toContain('MediaAdvisor');
    expect($text)->toContain('self-hosted media stack');
});

test('can be faked and prompted', function (): void {
    MediaAdvisorAgent::fake(['Here are your unwatched series: Show A, Show B.']);

    $user = User::factory()->admin()->create();
    $agentResponse = (new MediaAdvisorAgent)->forUser($user)->prompt('What have I not watched lately?');

    expect($agentResponse->text)->toContain('unwatched');
    MediaAdvisorAgent::assertPrompted('What have I not watched lately?');
});
