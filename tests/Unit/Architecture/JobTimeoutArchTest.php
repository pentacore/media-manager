<?php

declare(strict_types=1);

use App\Notifications\ServiceUpdateAvailable;
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

test('every queued job and queued notification declares a timeout below the worker --timeout', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];
    $scanned = [];

    // A queued notification runs as a SendQueuedNotifications job that copies
    // the notification's #[Timeout]/$timeout, so it inherits the worker's
    // 300 s when it declares none.
    foreach (['Jobs', 'Notifications'] as $directory) {
        foreach (Finder::create()->files()->in($root.'/app/'.$directory)->name('*.php') as $finder) {
            $class = 'App\\'.$directory.'\\'.str_replace(['/', '.php'], ['\\', ''], $finder->getRelativePathname());

            if (! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            $scanned[] = $class;
            $timeout = jobTimeoutArchDeclaredTimeout(new ReflectionClass($class));

            if ($timeout === null || $timeout >= JOB_TIMEOUT_ARCH_WORKER_TIMEOUT) {
                $offenders[$class] = $timeout;
            }
        }
    }

    // The scanner must actually reach queued notifications, or it proves nothing.
    expect($scanned)->toContain(ServiceUpdateAvailable::class)
        ->and($offenders)->toBe([]);
});
