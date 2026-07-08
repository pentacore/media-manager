<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use RectorLaravel\Rector\StaticCall\RouteActionCallableRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        naming: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(phpunit: true, laravel: true)
    ->withImportNames()
    ->withAttributesSets()
    ->withSets(
        [
            LaravelSetList::LARAVEL_CODE_QUALITY,
            LaravelSetList::LARAVEL_COLLECTION,
            LaravelSetList::LARAVEL_IF_HELPERS,
            //            LaravelSetList::LARAVEL_STATIC_TO_INJECTION,
            LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
            LaravelSetList::LARAVEL_TESTING,
            LaravelSetList::LARAVEL_FACTORIES,
            LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
            LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        ]
    )
    ->withConfiguredRule(
        RouteActionCallableRector::class,
        [
            'NAMESPACES' => ['App\\Http\\Controllers\\'],
        ]
    )
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/public',
        __DIR__.'/resources',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkipPath(__DIR__.'/bootstrap/cache')
    ->withSkip([
        PostIncDecToPreIncDecRector::class,
    ]);
