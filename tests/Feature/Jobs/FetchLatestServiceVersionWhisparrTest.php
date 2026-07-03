<?php

declare(strict_types=1);

use App\Jobs\FetchLatestServiceVersion;

test('REPO_MAP includes the Whisparr GitHub repo', function (): void {
    expect(FetchLatestServiceVersion::REPO_MAP['whisparr'] ?? null)->toBe('Whisparr/Whisparr');
});
