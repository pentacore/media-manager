<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;

test('resource maps version to the upstream API resource', function (): void {
    expect(WhisparrVersion::V3->resource())->toBe('movie');
    expect(WhisparrVersion::V2->resource())->toBe('series');
});

test('label is human readable', function (): void {
    expect(WhisparrVersion::V3->label())->toBe('v3 (movie-based)');
    expect(WhisparrVersion::V2->label())->toBe('v2 (Eros / series-based)');
});

test('values exposes the backed values for validation', function (): void {
    expect(WhisparrVersion::values())->toEqualCanonicalizing(['v2', 'v3']);
});
