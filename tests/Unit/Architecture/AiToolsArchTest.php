<?php

declare(strict_types=1);

use App\Ai\Tools\BaseTool;
use App\Ai\Tools\Decision\DecisionTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Ai\Tools\Decision\RemoveStuckDownloadTool;
use App\Ai\Tools\Decision\ResolveManualImportTool;

arch('every tool under app/Ai/Tools/ extends BaseTool, except the DecisionAgent tools')
    ->expect('App\Ai\Tools')
    ->classes()
    ->toExtend(BaseTool::class)
    ->ignoring([
        BaseTool::class,
        DecisionTool::class,
        ProposeActionTool::class,
        RemoveStuckDownloadTool::class,
        ResolveManualImportTool::class,
        InspectStuckImportTool::class,
    ]);

arch('every DecisionAgent tool under app/Ai/Tools/Decision extends DecisionTool')
    ->expect('App\Ai\Tools\Decision')
    ->classes()
    ->toExtend(DecisionTool::class)
    ->ignoring([DecisionTool::class]);

// Note: no arch test for "subclasses don't override handle()" — BaseTool::handle() is
// declared `final`, so PHP enforces this at compile time. Pest's `toHaveMethod` uses
// ReflectionClass::hasMethod() which includes inherited methods, so it would false-positive
// on every legitimate subclass.
