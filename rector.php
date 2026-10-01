<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use RectorLaravel\Rector\StaticCall\RouteActionCallableRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\LaravelSetProvider;

// Rector refuses a container cache directory that does not exist yet.
$containerCacheDirectory = __DIR__.'/storage/framework/cache/rector-container';
if (! is_dir($containerCacheDirectory)) {
    mkdir($containerCacheDirectory, 0777, true);
}

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
    // On the bind mount, not the container's /tmp: a run as root (sail exec)
    // left root-owned cache files there that the sail user cannot overwrite.
    // That covers Rector's internal PHPStan container cache too, which
    // otherwise lands in /tmp/cache.
    ->withCache(
        cacheDirectory: __DIR__.'/storage/framework/cache/rector',
        containerCacheDirectory: $containerCacheDirectory,
    )
    ->withSkipPath(__DIR__.'/bootstrap/cache')
    ->withSkip([
        PostIncDecToPreIncDecRector::class,
        // Parameter/promoted-property renames silently break named-argument
        // call sites (parameter names are part of the public signature in PHP 8).
        RenameParamToMatchTypeRector::class,
        RenamePropertyToMatchTypeRector::class,
        // Laravel's event auto-discovery binds a Listener::handle() to an
        // event solely from that parameter's type hint (these classes are
        // never registered in a provider). Stripping an apparently-unused
        // one silently unbinds the listener instead of just tidying dead code.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__.'/app/Listeners',
        ],
        // Splitting a grouped trait `use` drops its `insteadof` block, which
        // structured sub-agents need to resolve the stream() collision with Promptable.
        SeparateMultiUseImportsRector::class => [
            __DIR__.'/app/Ai/Agents',
        ],
    ]);
