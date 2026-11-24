<?php

declare(strict_types=1);

use App\Ai\ModelCatalog;

test('a keyless provider counts as configured when its driver is ollama, whatever its name', function (): void {
    config()->set('ai.providers', [
        'openai' => ['driver' => 'openai', 'key' => 'sk-test'],
        'local-llm' => ['driver' => 'ollama', 'url' => 'http://ollama.local:11434'],
        'anthropic' => ['driver' => 'anthropic', 'key' => null],
    ]);

    expect(resolve(ModelCatalog::class)->textProviders())->toBe(['openai', 'local-llm']);
});
