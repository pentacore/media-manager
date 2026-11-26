<?php

declare(strict_types=1);

use Laravel\Boost\Rules\RuleFrontmatter;
use Symfony\Component\Process\Process;

/**
 * Agents open a `.ai/rules` file only when one of its `paths:` globs matches
 * the file they edit, so a glob that matches nothing hides its rules. Every
 * glob must match at least one tracked file (`git ls-files`, so the gitignored
 * Wayfinder output never keeps a glob alive). `**` spans any number of path
 * segments, `*` and `?` stay within one, and `{a,b}` is an alternation.
 */
function aiRuleGlobsRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return list<string>|null null when git cannot list the files (a worktree
 *                           inside Sail, whose git directory is a host path)
 */
function aiRuleGlobsTrackedFiles(): ?array
{
    static $files = false;

    if ($files !== false) {
        return $files;
    }

    $process = new Process(['git', 'ls-files', '-z'], aiRuleGlobsRoot());
    $process->run();

    $files = $process->isSuccessful()
        ? array_values(array_filter(explode("\0", $process->getOutput()), static fn (string $path): bool => $path !== ''))
        : null;

    return $files;
}

function aiRuleGlobRegex(string $glob): string
{
    $regex = '';
    $length = strlen($glob);
    $inBraces = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $glob[$i];

        if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
            $i++;

            if (($glob[$i + 1] ?? '') === '/') {
                $i++;
                $regex .= '(?:.*/)?';
            } else {
                $regex .= '.*';
            }

            continue;
        }

        if ($char === '{') {
            $inBraces = true;
        }

        $regex .= match (true) {
            $char === '*' => '[^/]*',
            $char === '?' => '[^/]',
            $char === '{' => '(?:',
            $char === '}' && $inBraces => ')',
            $char === ',' && $inBraces => '|',
            default => preg_quote($char, '#'),
        };

        if ($char === '}') {
            $inBraces = false;
        }
    }

    return sprintf('#^%s$#', $regex);
}

/**
 * @param  list<string>  $files
 */
function aiRuleGlobMatchesAny(string $glob, array $files): bool
{
    $regex = aiRuleGlobRegex($glob);

    return array_any($files, static fn (string $file): bool => preg_match($regex, $file) === 1);
}

/**
 * @return array<string, array{string}>
 */
function aiRuleGlobsRuleFiles(): array
{
    $files = glob(aiRuleGlobsRoot().'/.ai/rules/*.md') ?: [];

    $rules = [];

    foreach ($files as $file) {
        if (basename($file) === 'index.md') {
            continue;
        }

        $rules[basename($file)] = [basename($file)];
    }

    return $rules;
}

test('every rule glob matches at least one tracked file', function (string $ruleFile): void {
    $tracked = aiRuleGlobsTrackedFiles();

    if ($tracked === null) {
        $this->markTestSkipped('git ls-files is unavailable here (a worktree inside Sail); run it from the main checkout or with GIT_DIR set.');
    }

    $paths = RuleFrontmatter::parse((string) file_get_contents(aiRuleGlobsRoot().'/.ai/rules/'.$ruleFile))['paths'];

    expect($paths)->not->toBeEmpty(sprintf('.ai/rules/%s has no paths: globs.', $ruleFile));

    $dead = array_values(array_filter($paths, static fn (string $glob): bool => ! aiRuleGlobMatchesAny($glob, $tracked)));

    expect($dead)->toBe([], sprintf('.ai/rules/%s has globs that match no tracked file: %s', $ruleFile, implode(', ', $dead)));
})->with(aiRuleGlobsRuleFiles());

test('the rule glob matcher follows the glob syntax', function (string $glob, string $path, bool $matches): void {
    expect(aiRuleGlobMatchesAny($glob, [$path]))->toBe($matches);
})->with([
    ['**', 'app/Models/User.php', true],
    ['app/Services/Actions/**', 'app/Services/Actions/BulkRunner.php', true],
    ['app/Services/Actions/**', 'app/Services/Actions/Sub/Deep.php', true],
    ['app/Services/Actions/**', 'app/Services/ActionsExtra.php', false],
    ['app/Models/*.php', 'app/Models/User.php', true],
    ['app/Models/*.php', 'app/Models/Sub/User.php', false],
    ['tests/**/Chat*Test.php', 'tests/ChatTurnGuardTest.php', true],
    ['tests/**/Chat*Test.php', 'tests/Feature/Ai/ChatTurnGuardTest.php', true],
    ['app/Models/{ActionRequest,ActionTypeConfig}.php', 'app/Models/ActionTypeConfig.php', true],
    ['app/Models/{ActionRequest,ActionTypeConfig}.php', 'app/Models/User.php', false],
    ['app/Http/Requests/Library/Bulk?ibraryActionRequest.php', 'app/Http/Requests/Library/BulkLibraryActionRequest.php', true],
    ['app/Models/User.php, app/Models/Server.php', 'app/Models/User.php', false],
]);

test('a glob that matches no tracked file is reported as dead', function (): void {
    $tracked = aiRuleGlobsTrackedFiles();

    if ($tracked === null) {
        $this->markTestSkipped('git ls-files is unavailable here (a worktree inside Sail); run it from the main checkout or with GIT_DIR set.');
    }

    expect($tracked)->not->toBeEmpty()
        ->and(aiRuleGlobMatchesAny('app/NoSuchFolder/**', $tracked))->toBeFalse()
        ->and(aiRuleGlobMatchesAny('app/Models/*.php', $tracked))->toBeTrue();
});
