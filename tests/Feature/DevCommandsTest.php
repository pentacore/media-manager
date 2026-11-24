<?php

declare(strict_types=1);

use Illuminate\Foundation\DevCommands;

test('php artisan dev runs octane, every queue lane, logs, vite and reverb', function (): void {
    $commands = collect(DevCommands::commands())->pluck('command', 'name');

    expect($commands->keys()->sort()->values()->all())->toBe(['logs', 'queue', 'reverb', 'server', 'vite'])
        ->and($commands['server'])->toContain('octane:start --watch')
        ->and($commands['queue'])->toContain('queue:listen --queue=actions,webhooks,default,ai,maintenance');
});
