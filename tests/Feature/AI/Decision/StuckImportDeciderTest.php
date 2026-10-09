<?php

declare(strict_types=1);

use App\Ai\Decision\StuckImportDecider;
use App\Ai\Decision\StuckImportDecision;
use App\Enums\StuckImportChoice;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

function fakeDeciderManualImport(): void
{
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response([[
        'path' => '/dl/show.s01e01.mkv',
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
        'series' => ['id' => 5],
        'episodes' => [['id' => 11]],
        'rejections' => [['reason' => 'Not an upgrade for existing episode file(s)']],
    ]])]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function deciderSonarr(): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
}

/**
 * @return array<string, mixed>
 */
function deciderAnswers(string $choice, float $probability, float $blocklist = 0.1, float $search = 0.1): array
{
    return [
        'choice' => new ChoiceAnswer($choice, [$choice => $probability]),
        'blocklist' => new BooleanAnswer($blocklist),
        'search_replacement' => new BooleanAnswer($search),
    ];
}

test('one classification call asks the choice and both flags about the inspection', function (): void {
    fakeDeciderManualImport();
    Classification::fake([deciderAnswers('remove', 0.92)]);

    resolve(StuckImportDecider::class)->decide(deciderSonarr(), 'sonarr', 'dl-1');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('choice')
        && $prompt->asks('blocklist')
        && $prompt->asks('search_replacement')
        && $prompt->contains('Not an upgrade'));
});

test('a choice at or above the threshold is confident', function (float $probability, bool $confident): void {
    fakeDeciderManualImport();
    Classification::fake([deciderAnswers('remove', $probability)]);

    $decision = resolve(StuckImportDecider::class)->decide(deciderSonarr(), 'sonarr', 'dl-1');

    expect($decision)->toBeInstanceOf(StuckImportDecision::class)
        ->and($decision->choice)->toBe(StuckImportChoice::Remove)
        ->and($decision->isConfident)->toBe($confident);
})->with([
    'above' => [0.92, true],
    'exactly at' => [0.85, true],
    'below' => [0.84, false],
]);

test('the threshold follows the setting', function (): void {
    fakeDeciderManualImport();
    resolve(AiSettings::class)->setStuckImportThreshold(0.95);
    Classification::fake([deciderAnswers('import', 0.9)]);

    expect(resolve(StuckImportDecider::class)->decide(deciderSonarr(), 'sonarr', 'dl-1')->isConfident)->toBeFalse();
});

test('blocklist and search replacement are set only for removals at the flag threshold', function (): void {
    fakeDeciderManualImport();
    Classification::fake([
        deciderAnswers('remove', 0.95, blocklist: 0.8, search: 0.79),
        deciderAnswers('import', 0.95, blocklist: 0.99, search: 0.99),
    ]);
    $stuckImportDecider = resolve(StuckImportDecider::class);

    $removal = $stuckImportDecider->decide(deciderSonarr(), 'sonarr', 'dl-1');
    $import = $stuckImportDecider->decide(deciderSonarr(), 'sonarr', 'dl-1');

    expect($removal->blocklist)->toBeTrue()
        ->and($removal->searchReplacement)->toBeFalse()
        ->and($import->blocklist)->toBeFalse()
        ->and($import->searchReplacement)->toBeFalse();
});

test('an unknown choice or a malformed answer is no decision', function (array $answers): void {
    fakeDeciderManualImport();
    Classification::fake([$answers]);

    expect(resolve(StuckImportDecider::class)->decide(deciderSonarr(), 'sonarr', 'dl-1'))->toBeNull();
})->with([
    'unknown option' => [['choice' => new ChoiceAnswer('delete_everything', ['delete_everything' => 0.99]), 'blocklist' => new BooleanAnswer(0.1), 'search_replacement' => new BooleanAnswer(0.1)]],
    // FakeClassificationGateway auto-fills any question key missing from the fake
    // response with a randomly-generated but always-valid answer drawn from the
    // real question options, so a literally missing "choice" key can never reach
    // the decider as unusable. Use a wrong-type answer to exercise the same
    // "unusable answer" branch (`$choiceAnswer instanceof ChoiceAnswer`) instead.
    'malformed choice' => [['choice' => new BooleanAnswer(0.9), 'blocklist' => new BooleanAnswer(0.1), 'search_replacement' => new BooleanAnswer(0.1)]],
]);

test('a classifier failure is no decision', function (): void {
    fakeDeciderManualImport();
    Classification::fake(fn () => throw new RuntimeException('down'));

    expect(resolve(StuckImportDecider::class)->decide(deciderSonarr(), 'sonarr', 'dl-1'))->toBeNull();
});

test('a failed inspection is no decision and never classifies', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/manualimport*' => Http::response('boom', 500)]);
    Classification::fake([]);

    expect(resolve(StuckImportDecider::class)->decide(deciderSonarr(), 'sonarr', 'dl-1'))->toBeNull();
    Classification::assertNothingClassified();
});
