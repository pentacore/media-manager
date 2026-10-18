<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Query-builder updates that change an action request's status without also
 * setting status_version, as "path: first line" strings. A builder update
 * skips the model observers, so ActionRequestStatusVersionObserver never sees
 * it: the writer must set 'status_version' => ActionRequest::statusVersionExpression()
 * in the same array, so the UPDATE takes the number once it holds the row.
 *
 * It flags an `->update([...])` whose chain starts at `ActionRequest::` (not
 * a longer name ending in it, such as `ExecuteActionRequest::`) or at
 * `table('action_requests')` in the same statement and whose array literal
 * has a 'status' key but no 'status_version' key. A chain that loads a model
 * first (`first()`, `firstOrFail()`, `firstWhere()`, `firstOr()`, `find()`,
 * `findOrFail()`, `sole()`, called with `->` or statically with `::`) ends in
 * a model save, which the observer numbers. Only a link of the chain itself
 * counts: the arguments are blanked first, so a loader inside one
 * (`whereKey($ids->first())`) leaves the update a builder update.
 *
 * It cannot see:
 * - a builder kept in a variable and updated in a later statement;
 * - a relation builder (`$attempt->actionRequest()->update([...])`,
 *   `$webhookEvent->actionRequests()->update([...])`) or `newQuery()` on an
 *   instance, because the chain does not start at `ActionRequest::`;
 * - a chain with a `;` inside it before `->update(`, such as a closure
 *   (`->where(function ($query) { ...; })`) or a string;
 * - raw SQL (`DB::update()`, `DB::statement()`, `DB::unprepared()`);
 * - other builder writes (`upsert()`, `insert()`, `insertOrIgnore()`, `updateOrInsert()`, `increment()`);
 * - an update array built in a variable, or merged in with `+` or a spread;
 * - a status key spelled through a constant.
 *
 * Review those by hand.
 *
 * @return list<string>
 */
function actionRequestStatusVersionOffenders(string $path, string $code): array
{
    $offenders = [];

    preg_match_all(
        "/(?:(?<!\w)ActionRequest::|table\(\s*['\"]action_requests['\"]\s*\))[^;]*?->update\(\s*\[(.*?)\]\s*\)\s*;/s",
        $code,
        $matches,
        PREG_SET_ORDER,
    );

    foreach ($matches as [$statement, $array]) {
        $chain = substr($statement, 0, (int) strrpos($statement, '->update('));

        do {
            $chain = (string) preg_replace('/\([^()]*\)/', '<>', $chain, -1, $blanked);
        } while ($blanked > 0);

        if (preg_match('/(?:->|::)(?:first|firstOrFail|firstWhere|firstOr|find|findOrFail|sole)<>/', $chain) === 1) {
            continue;
        }

        if (preg_match("/['\"]status['\"]\s*=>/", $array) === 1 && preg_match("/['\"]status_version['\"]\s*=>/", $array) !== 1) {
            $offenders[] = sprintf('%s: %s', $path, trim((string) strtok($statement, "\n")));
        }
    }

    return $offenders;
}

test('the status-version scanner flags a builder status update without a version and passes the rest', function (): void {
    $code = <<<'PHP'
        ActionRequest::query()->whereKey($id)->update(['status' => ActionRequestStatus::Executing]);
        ActionRequest::query()
            ->whereKey($id)
            ->update([
                'status' => ActionRequestStatus::Failed->value,
                'status_version' => ActionRequest::statusVersionExpression(),
                'result' => json_encode(['reason' => 'x']),
            ]);
        DB::table('action_requests')->where('id', $id)->update(['status' => 'failed']);
        ActionRequest::query()->whereKey($id)->update(['updated_at' => now()]);
        ActionRequest::query()->whereKey($id)->firstOrFail()->update(['status' => ActionRequestStatus::Rejected]);
        ActionRequest::find($id)->update(['status' => ActionRequestStatus::Rejected]);
        ActionRequest::findOrFail($id)->update(['status' => ActionRequestStatus::Rejected]);
        ActionRequest::query()->firstWhere('id', $id)->update(['status' => ActionRequestStatus::Rejected]);
        ActionRequest::find($ids->first())->update(['status' => ActionRequestStatus::Rejected]);
        ActionRequest::query()->whereKey($ids->first())->update(['status' => ActionRequestStatus::Approved]);
        ActionRequest::query()->whereIn('id', $rows->map(fn ($row) => $row->find($id))->all())->update(['status' => 'failed']);
        $actionRequest->update(['status' => ActionRequestStatus::Approved]);
        ExecuteActionRequest::dispatch($x);
        StagedActionRequest::query()->whereKey($id)->update(['status' => 'failed']);
        \App\Models\ActionRequest::query()->whereKey($id)->update(['status' => 'rejected']);
        PHP;

    expect(actionRequestStatusVersionOffenders('app/Example.php', $code))->toBe([
        "app/Example.php: ActionRequest::query()->whereKey(\$id)->update(['status' => ActionRequestStatus::Executing]);",
        "app/Example.php: table('action_requests')->where('id', \$id)->update(['status' => 'failed']);",
        "app/Example.php: ActionRequest::query()->whereKey(\$ids->first())->update(['status' => ActionRequestStatus::Approved]);",
        "app/Example.php: ActionRequest::query()->whereIn('id', \$rows->map(fn (\$row) => \$row->find(\$id))->all())->update(['status' => 'failed']);",
        "app/Example.php: ActionRequest::query()->whereKey(\$id)->update(['status' => 'rejected']);",
    ]);
});

test('every query-builder status update on action requests also sets status_version', function (): void {
    $root = dirname(__DIR__, 3);
    $offenders = [];
    $scanned = 0;

    foreach (['app', 'database'] as $directory) {
        foreach (Finder::create()->files()->in(sprintf('%s/%s', $root, $directory))->name('*.php') as $finder) {
            $scanned++;
            $offenders = [
                ...$offenders,
                ...actionRequestStatusVersionOffenders(sprintf('%s/%s', $directory, $finder->getRelativePathname()), $finder->getContents()),
            ];
        }
    }

    // The scanner must actually walk the code, or it proves nothing.
    expect($scanned)->toBeGreaterThan(200)
        ->and($offenders)->toBe([]);
});
