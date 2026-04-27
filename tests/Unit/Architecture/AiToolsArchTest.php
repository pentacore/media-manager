<?php

declare(strict_types=1);

use App\Ai\Tools\BaseTool;

arch('every tool under app/Ai/Tools/ extends BaseTool')
    ->expect('App\Ai\Tools')
    ->classes()
    ->toExtend(BaseTool::class)
    ->ignoring(['App\Ai\Tools\BaseTool']);

// Note: no arch test for "subclasses don't override handle()" — BaseTool::handle() is
// declared `final`, so PHP enforces this at compile time. Pest's `toHaveMethod` uses
// ReflectionClass::hasMethod() which includes inherited methods, so it would false-positive
// on every legitimate subclass.
