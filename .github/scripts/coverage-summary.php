<?php

declare(strict_types=1);

/*
 * Turns a Clover coverage report into a Markdown summary: overall line and
 * method coverage, then line coverage per top-level app/ directory.
 *
 * Usage: php .github/scripts/coverage-summary.php build/coverage/clover.xml
 */

$path = $argv[1] ?? '';

if (! is_file($path)) {
    fwrite(STDERR, "Coverage report [{$path}] not found.\n");
    exit(1);
}

$clover = simplexml_load_file($path);

if ($clover === false) {
    fwrite(STDERR, "Coverage report [{$path}] is not valid XML.\n");
    exit(1);
}

$percent = static fn (int $covered, int $total): string => $total === 0
    ? 'n/a'
    : number_format($covered / $total * 100, 1).' %';

$metrics = $clover->project->metrics;
$lines = [(int) $metrics['coveredstatements'], (int) $metrics['statements']];
$methods = [(int) $metrics['coveredmethods'], (int) $metrics['methods']];

$root = realpath(getcwd()).'/app/';
$areas = [];

foreach ($clover->xpath('//file') ?: [] as $file) {
    $name = (string) $file['name'];
    $relative = str_starts_with($name, $root) ? substr($name, strlen($root)) : basename($name);
    $area = str_contains($relative, '/') ? strstr($relative, '/', true) : '(root)';
    $areas[$area] ??= [0, 0];
    $areas[$area][0] += (int) $file->metrics['coveredstatements'];
    $areas[$area][1] += (int) $file->metrics['statements'];
}

ksort($areas);

$out = [
    '## Coverage (Unit + Feature)',
    '',
    sprintf('Lines: **%s** (%d / %d) · Methods: **%s** (%d / %d)', $percent(...$lines), $lines[0], $lines[1], $percent(...$methods), $methods[0], $methods[1]),
    '',
    '| app/ | Lines | Covered / total |',
    '| --- | ---: | ---: |',
];

foreach ($areas as $area => [$covered, $total]) {
    $out[] = sprintf('| %s | %s | %d / %d |', $area, $percent($covered, $total), $covered, $total);
}

echo implode("\n", $out)."\n";
