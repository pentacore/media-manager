<?php

declare(strict_types=1);

use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\Telescope;

beforeEach(function (): void {
    $this->hiddenRequestHeaders = Telescope::$hiddenRequestHeaders;
    $this->hiddenRequestParameters = Telescope::$hiddenRequestParameters;
    $this->hiddenResponseParameters = Telescope::$hiddenResponseParameters;
});

afterEach(function (): void {
    Telescope::$hiddenRequestHeaders = $this->hiddenRequestHeaders;
    Telescope::$hiddenRequestParameters = $this->hiddenRequestParameters;
    Telescope::$hiddenResponseParameters = $this->hiddenResponseParameters;
});

test('telescope never records agent credentials or the watcher push url', function (string $environment): void {
    $this->app->detectEnvironment(fn (): string => $environment);

    new ReflectionMethod(TelescopeServiceProvider::class, 'hideSensitiveRequestDetails')->invoke(new TelescopeServiceProvider($this->app));

    expect(Telescope::$hiddenRequestHeaders)->toContain('authorization')
        ->and(Telescope::$hiddenRequestParameters)->toContain('enrollment_token', 'agent_token', 'push_url')
        ->and(Telescope::$hiddenResponseParameters)->toContain('agent_token');
})->with(['local', 'production']);
