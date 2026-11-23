<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * A missing connection is an ordinary state: look it up with
 * ServiceConnection::findActive() and branch on null. resolveActive() is for
 * callers where a missing connection is an error (executors, AI tools), so
 * no class may catch its ModelNotFoundException to mean "not configured".
 * The catch is matched on resolved class names, so an aliased import, a
 * fully qualified name or a supertype (Exception, RuntimeException,
 * Throwable) around a resolveActive() call is caught as well.
 * The pinned lookups' own
 * `catch (InvalidArgumentException|ModelNotFoundException)` sit in files
 * that no longer call resolveActive().
 */
const CONNECTION_LOOKUP_ARCH_CAUGHT_TYPES = [
    'illuminate\database\eloquent\modelnotfoundexception',
    'illuminate\database\recordsnotfoundexception',
    'exception',
    'runtimeexception',
    'throwable',
];

/**
 * Files in which a broad catch wraps a resolveActive() call but does not
 * treat the missing connection as "not configured": it records or rethrows
 * every failure of a larger block.
 *
 * @return list<string> paths relative to app/
 */
function connectionLookupReviewedFiles(): array
{
    return [
        // Logs every failure of the whole inspection and answers lookup_failed;
        // a missing connection is reported as a failure, not as "not configured".
        'Ai/Tools/Decision/InspectStuckImportTool.php',
    ];
}

/**
 * @return list<string> descriptions of every catch around a resolveActive() call that would also swallow its ModelNotFoundException
 */
function connectionLookupSwallowingCatches(string $code): array
{
    $statements = new ParserFactory()->createForHostVersion()->parse($code) ?? [];
    $traverser = new NodeTraverser(new NameResolver);
    $statements = $traverser->traverse($statements);
    $nodeFinder = new NodeFinder;
    $found = [];

    foreach ($nodeFinder->findInstanceOf($statements, TryCatch::class) as $tryCatch) {
        $callsResolveActive = $nodeFinder->findFirst(
            $tryCatch->stmts,
            static fn (Node $node): bool => ($node instanceof StaticCall || $node instanceof MethodCall)
                && $node->name instanceof Identifier
                && $node->name->toString() === 'resolveActive',
        ) instanceof Node;

        if (! $callsResolveActive) {
            continue;
        }

        foreach ($tryCatch->catches as $catch) {
            foreach ($catch->types as $type) {
                if (in_array(strtolower($type->toString()), CONNECTION_LOOKUP_ARCH_CAUGHT_TYPES, true)) {
                    $found[] = sprintf('catch (%s) at line %d', $type->toString(), $catch->getStartLine());
                }
            }
        }
    }

    return $found;
}

test('the lookup scanner sees through an alias, a qualified name and a supertype', function (string $catch): void {
    $code = <<<PHP
        <?php
        use Illuminate\\Database\\Eloquent\\ModelNotFoundException as Missing;
        use Illuminate\\Database\\RecordsNotFoundException;
        function probe() {
            try {
                \$connection = ServiceConnection::resolveActive('sonarr');
            } $catch {
                return null;
            }
        }
        PHP;

    expect(connectionLookupSwallowingCatches($code))->toHaveCount(1);
})->with([
    'aliased import' => 'catch (Missing $e)',
    'fully qualified' => 'catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e)',
    'records not found' => 'catch (RecordsNotFoundException $e)',
    'in a multi-catch' => 'catch (InvalidArgumentException|Missing $e)',
    'exception' => 'catch (\Exception $e)',
    'throwable' => 'catch (Throwable $e)',
]);

test('the lookup scanner ignores an unrelated catch and a catch around other calls', function (): void {
    $code = <<<'PHP'
        <?php
        function probe() {
            try {
                ServiceConnection::resolveActive('sonarr');
            } catch (InvalidArgumentException $e) {
                return null;
            }
            try {
                ServiceConnection::findActive('sonarr');
            } catch (Throwable $e) {
                return null;
            }
        }
        PHP;

    expect(connectionLookupSwallowingCatches($code))->toBe([]);
});

test('no class catches the exception of resolveActive() to mean "not configured"', function (): void {
    $root = dirname(__DIR__, 3);
    $reviewed = connectionLookupReviewedFiles();
    $offenders = [];

    foreach (Finder::create()->files()->in($root.'/app')->name('*.php') as $finder) {
        $code = $finder->getContents();

        if (! str_contains($code, 'resolveActive(') || in_array($finder->getRelativePathname(), $reviewed, true)) {
            continue;
        }

        foreach (connectionLookupSwallowingCatches($code) as $description) {
            $offenders[] = sprintf('%s: %s', $finder->getRelativePathname(), $description);
        }
    }

    sort($offenders);

    expect($offenders)->toBe([]);
});
