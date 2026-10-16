<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\System\OfferChoicesTool;
use Laravel\Ai\Tools\Request;

/**
 * @param  list<array<string, mixed>>  $options
 * @return array<string, mixed>
 */
function offerChoices(array $options, array $extra = []): array
{
    return json_decode((new OfferChoicesTool)->handle(new Request([
        'question' => 'Which download?',
        'options' => $options,
        ...$extra,
    ])), true);
}

test('returns the choices payload with trimmed labels', function (): void {
    $result = offerChoices([
        ['label' => '  Show S01E01 (1080p) '],
        ['label' => 'Show S01E01 (720p)', 'description' => 'Smaller file'],
    ]);

    expect($result['choices'])->toBe([
        'question' => 'Which download?',
        'multiple' => false,
        'options' => [
            ['label' => 'Show S01E01 (1080p)', 'description' => null],
            ['label' => 'Show S01E01 (720p)', 'description' => 'Smaller file'],
        ],
    ])->and($result['instruction'])->toContain('End your turn now');
});

test('multiple is passed through', function (): void {
    $result = offerChoices([['label' => 'A'], ['label' => 'B']], ['multiple' => true]);

    expect($result['choices']['multiple'])->toBeTrue();
});

test('rejects too few, too many and too long arguments', function (array $options, array $extra = []): void {
    expect(offerChoices($options, $extra)['error'])->toBe('invalid_arguments');
})->with([
    'one option' => [[['label' => 'A']]],
    'eleven options' => [array_fill(0, 11, ['label' => 'A'])],
    'question over 300 chars' => [[['label' => 'A'], ['label' => 'B']], ['question' => str_repeat('q', 301)]],
    'description over 160 chars' => [[['label' => 'A', 'description' => str_repeat('d', 161)], ['label' => 'B']]],
    'label over 80 chars' => [[['label' => str_repeat('x', 81)], ['label' => 'B']]],
]);

test('risk is Read', function (): void {
    expect((new OfferChoicesTool)->risk())->toBe(Risk::Read);
});
