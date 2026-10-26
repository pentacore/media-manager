<?php

declare(strict_types=1);

use App\Services\MediaReplacement\SubtitleAuditBatchSweeper;

test('the command runs the sweeper once and succeeds', function (): void {
    $this->mock(SubtitleAuditBatchSweeper::class)
        ->shouldReceive('sweep')
        ->once()
        ->andReturn(2);

    $this->artisan('subtitles:sweep-audit-batches')->assertSuccessful();
});
