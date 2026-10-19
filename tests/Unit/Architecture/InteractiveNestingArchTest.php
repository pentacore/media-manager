<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Interactive elements found inside a <Link> or <a> in one SFC, as
 * "line: tag". Prettier may split a closing tag across lines (`</a\n>`), so
 * the anchor ends at `</a` or `</Link` followed by optional whitespace and `>`.
 *
 * @return list<string>
 */
function interactiveNestingOffenders(string $code): array
{
    preg_match_all('/<(Link|a)\b[^>]*>((?:(?!<\/\1\s*>).)*?)<(Button|button|Checkbox|BulkCheckbox|input|select|textarea)\b/s', $code, $matches, PREG_OFFSET_CAPTURE);

    $offenders = [];

    foreach ($matches[3] as [$tag, $offset]) {
        $offenders[] = sprintf('%d: %s', substr_count($code, "\n", 0, $offset) + 1, $tag);
    }

    return $offenders;
}

test('the nesting scanner finds a button inside a link and ignores a link inside a button', function (): void {
    expect(interactiveNestingOffenders("<Link :href=\"x\">\n<Button>Go</Button>\n</Link>"))->toBe(['2: Button'])
        ->and(interactiveNestingOffenders("<Button as-child>\n<Link :href=\"x\">Go</Link>\n</Button>"))->toBe([])
        ->and(interactiveNestingOffenders("<a href=\"x\"\n>docs</a\n>\n<Button>Edit</Button>"))->toBe([]);
});

test('no link or anchor in a page or component wraps an interactive element', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];
    $scanned = 0;

    foreach (Finder::create()->files()->in($root.'/resources/js')->name('*.vue')->notPath('components/ui') as $finder) {
        $scanned++;
        $path = str_replace($root.'/', '', $finder->getPathname());

        foreach (interactiveNestingOffenders((string) file_get_contents($finder->getPathname())) as $offender) {
            $offenders[] = sprintf('%s:%s', $path, $offender);
        }
    }

    // The scan must actually walk the components, or it proves nothing.
    expect($scanned)->toBeGreaterThan(200)
        ->and($offenders)->toBe([]);
});
