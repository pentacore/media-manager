<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\HostMonitoring\EnrollmentInstructions;

test('the install command passes the platform url through the shell whole', function (string $appUrl, string $urlArgument): void {
    config()->set('app.url', $appUrl);
    $server = Server::factory()->pendingEnrollment()->create();

    $instructions = resolve(EnrollmentInstructions::class)->for($server, 'Token0123456789Token0123456789AB');

    expect($instructions['debian_command'])
        ->toBe(sprintf('curl -fsSL https://github.com/pentacore/lookout/releases/latest/download/install.sh | sudo sh -s -- --url %s --token Token0123456789Token0123456789AB', $urlArgument))
        ->and($instructions['platform_url'])->toBe($appUrl);
})->with([
    'plain https url' => ['https://media.example.test', 'https://media.example.test'],
    'port and path' => ['http://babel.tailnet-1.ts.net:8080/media_manager', 'http://babel.tailnet-1.ts.net:8080/media_manager'],
    'shell metacharacters' => ['https://media.example.test/?a=1&b=2', "'https://media.example.test/?a=1&b=2'"],
    'a quote' => ["https://media.example.test/it's", "'https://media.example.test/it'\\''s'"],
]);
