<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `computed()` calls in one SFC whose getter reads a slot, as "line". Slots
 * are not reactive, so a computed over `slots.x` / `$slots.x` caches the first
 * answer and never sees a parent's conditional slot come or go; read slots in
 * render (the template or a plain function it calls) instead.
 *
 * @return list<string>
 */
function slotReactivityOffenders(string $code): array
{
    preg_match_all('/\bcomputed\s*(?:<[^>]*>)?\(\s*\(\)\s*=>\s*(?:\{[^}]*\}|[^;]*?;)/s', $code, $matches, PREG_OFFSET_CAPTURE);

    $offenders = [];

    foreach ($matches[0] as [$getter, $offset]) {
        if (preg_match('/(?<![\w.])\$?slots\.\w/', $getter) === 1) {
            $offenders[] = (string) (substr_count($code, "\n", 0, $offset) + 1);
        }
    }

    return $offenders;
}

test('the slot scanner finds a computed over slots and accepts a render-time read', function (): void {
    expect(slotReactivityOffenders("const slots = useSlots();\nconst compact = computed(() => slots.primary !== undefined);\n"))->toBe(['2'])
        ->and(slotReactivityOffenders("const has = computed(() => {\n    return \$slots.menu !== undefined;\n});\n"))->toBe(['1'])
        ->and(slotReactivityOffenders("function isCompact(): boolean {\n    return slots.primary !== undefined;\n}\n"))->toBe([])
        ->and(slotReactivityOffenders("const total = computed(() => history.slots.length);\n"))->toBe([]);
});

test('no page or component caches a slot read in a computed', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];
    $scanned = 0;

    foreach (Finder::create()->files()->in($root.'/resources/js')->name('*.vue')->notPath('components/ui') as $finder) {
        $scanned++;
        $path = str_replace($root.'/', '', $finder->getPathname());

        foreach (slotReactivityOffenders((string) file_get_contents($finder->getPathname())) as $line) {
            $offenders[] = sprintf('%s:%s', $path, $line);
        }
    }

    expect($scanned)->toBeGreaterThan(200)
        ->and($offenders)->toBe([]);
});
