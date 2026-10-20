<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Icon-only <Button>/<button> elements in one SFC that have no accessible
 * name, as "line: tag". A name is an aria-label, aria-labelledby or title
 * (static or bound) on the element or anything inside it (as-child anchors),
 * .sr-only text, visible text or an interpolation, or v-html/v-text content.
 *
 * @return list<string>
 */
function iconButtonNameOffenders(string $code): array
{
    $start = strpos($code, '<template>');

    if ($start === false) {
        return [];
    }

    preg_match_all('/<(Button|button)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/\1\s*>/s', substr($code, $start), $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $named = '/(?:^|\s)(?:(?::|v-bind:)?(?:aria-label|aria-labelledby|title)=|v-html=|v-text=)/';
    $offenders = [];

    foreach ($matches as $match) {
        $opening = $match[2][0];
        $inner = $match[3][0];

        if (preg_match($named, $opening) === 1 || preg_match($named, $inner) === 1 || str_contains($inner, 'sr-only')) {
            continue;
        }

        if (trim((string) preg_replace('/<[^>]*>/s', '', $inner)) !== '') {
            continue;
        }

        $offenders[] = sprintf('%d: %s', substr_count($code, "\n", 0, $start + $match[0][1]) + 1, $match[1][0]);
    }

    return $offenders;
}

test('the name scanner finds an unnamed icon button and accepts every naming pattern', function (): void {
    expect(iconButtonNameOffenders("<template>\n<Button size=\"icon\" @click=\"() => close()\">\n<X class=\"size-4\" />\n</Button>\n</template>"))->toBe(['2: Button'])
        ->and(iconButtonNameOffenders("<template>\n<Button aria-label=\"Close\"><X /></Button>\n</template>"))->toBe([])
        ->and(iconButtonNameOffenders("<template>\n<button :aria-label=\"label\"><X /></button>\n</template>"))->toBe([])
        ->and(iconButtonNameOffenders("<template>\n<Button><X /><span class=\"sr-only\">Close</span></Button>\n</template>"))->toBe([])
        ->and(iconButtonNameOffenders("<template>\n<Button as-child><a :href=\"url\" title=\"Open\"><X /></a></Button>\n</template>"))->toBe([])
        ->and(iconButtonNameOffenders("<template>\n<Button>{{ label }}</Button>\n</template>"))->toBe([])
        ->and(iconButtonNameOffenders("<template>\n<Button><span v-html=\"link.label\" /></Button>\n</template>"))->toBe([]);
});

test('every icon-only button in a page or component has an accessible name', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];
    $scanned = 0;

    foreach (Finder::create()->files()->in($root.'/resources/js')->name('*.vue')->notPath('components/ui') as $finder) {
        $scanned++;
        $path = str_replace($root.'/', '', $finder->getPathname());

        foreach (iconButtonNameOffenders((string) file_get_contents($finder->getPathname())) as $offender) {
            $offenders[] = sprintf('%s:%s', $path, $offender);
        }
    }

    expect($scanned)->toBeGreaterThan(200)
        ->and($offenders)->toBe([]);
});
