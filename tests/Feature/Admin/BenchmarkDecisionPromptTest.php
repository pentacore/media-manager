<?php

declare(strict_types=1);

use App\Ai\Decision\DecisionPrompt;
use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('the preview renders the prompt the decision agent will see', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $payloadJson = '{"zeta": 1, "10": "ten", "alpha": 2}';

    $this->postJson(route('admin.benchmarks.cases.decision-prompt'), ['service' => 'radarr', 'event_type' => 'Grab', 'payload_json' => $payloadJson])
        ->assertOk()
        ->assertJsonPath('prompt', DecisionPrompt::build('radarr', 'Grab', json_decode($payloadJson, true)));
});

test('the preview rejects a payload that is not a JSON object', function (string $payloadJson): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->postJson(route('admin.benchmarks.cases.decision-prompt'), ['service' => 'sonarr', 'event_type' => 'Grab', 'payload_json' => $payloadJson])
        ->assertUnprocessable()
        ->assertJsonPath('errors.payload_json.0', 'The payload must be a JSON object.');
})->with(['list' => ['[1, 2]'], 'scalar' => ['"x"'], 'invalid' => ['{bad']]);

test('the preview rejects an unknown service and a missing event type', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->postJson(route('admin.benchmarks.cases.decision-prompt'), ['service' => 'emby', 'event_type' => '', 'payload_json' => '{}'])
        ->assertUnprocessable();

    expect($response->json('errors'))->toHaveKeys(['service', 'event_type']);
});

test('non-admins cannot preview a decision prompt', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $this->postJson(route('admin.benchmarks.cases.decision-prompt'), ['service' => 'sonarr', 'event_type' => 'Grab', 'payload_json' => '{}'])
        ->assertForbidden();
});
