<?php

declare(strict_types=1);

use App\Ai\Tools\BaseTool;

arch('every tool under app/Ai/Tools/ extends BaseTool')
    ->expect('App\Ai\Tools')
    ->classes()
    ->toExtend(BaseTool::class)
    ->ignoring(['App\Ai\Tools\BaseTool'])
    // Phase 1 migration: ignore the legacy tools that are deleted in Task 12.
    ->ignoring([
        'App\Ai\Tools\CreateActionRequestTool',
        'App\Ai\Tools\SearchMediaTool',
        'App\Ai\Tools\QueryActivityTool',
        'App\Ai\Tools\GetServiceStatusTool',
    ]);

// Note: no arch test for "subclasses don't override handle()" — BaseTool::handle() is
// declared `final`, so PHP enforces this at compile time. Pest's `toHaveMethod` uses
// ReflectionClass::hasMethod() which includes inherited methods, so it would false-positive
// on every legitimate subclass.
