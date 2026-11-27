<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('webpush:keys prints a VAPID key pair', function (): void {
    $exitCode = Artisan::call('webpush:keys');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/^WEBPUSH_PUBLIC_KEY=[A-Za-z0-9_-]{80,}$/m')
        ->and($output)->toMatch('/^WEBPUSH_PRIVATE_KEY=[A-Za-z0-9_-]{40,}$/m');
});
