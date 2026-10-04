<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Symfony\Component\Finder\Finder;

/**
 * The queue workers' --timeout (docker/production/entrypoint.sh). Every job
 * must stop below it, and so below redis retry_after (330s) too.
 */
const JOB_TIMEOUT_ARCH_WORKER_TIMEOUT = 300;

/**
 * The timeout a job class declares: its #[Timeout] attribute, else its
 * `$timeout` property default, else null (it would inherit the worker's).
 *
 * @param  ReflectionClass<object>  $reflectionClass
 */
function jobTimeoutArchDeclaredTimeout(ReflectionClass $reflectionClass): ?int
{
    $attribute = $reflectionClass->getAttributes(Timeout::class)[0] ?? null;

    if ($attribute instanceof ReflectionAttribute) {
        return $attribute->newInstance()->timeout;
    }

    if (! $reflectionClass->hasProperty('timeout')) {
        return null;
    }

    $default = $reflectionClass->getProperty('timeout')->getDefaultValue();

    return is_int($default) ? $default : null;
}

test('every queued job declares a timeout below the worker --timeout', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];

    foreach (Finder::create()->files()->in($root.'/app/Jobs')->name('*.php') as $finder) {
        $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $finder->getRelativePathname());

        if (! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $timeout = jobTimeoutArchDeclaredTimeout(new ReflectionClass($class));

        if ($timeout === null || $timeout >= JOB_TIMEOUT_ARCH_WORKER_TIMEOUT) {
            $offenders[$class] = $timeout;
        }
    }

    expect($offenders)->toBe([]);
});
