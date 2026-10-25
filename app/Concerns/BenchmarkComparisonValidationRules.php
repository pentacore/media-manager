<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\BenchmarkRun;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The 2–5 finished runs a comparison (or a neutral re-judge of one) is about.
 */
trait BenchmarkComparisonValidationRules
{
    /**
     * `"1,2"` or `[1, 2]` as a list; anything else yields an empty list.
     *
     * @return list<int|string>
     */
    protected function runIdsFrom(mixed $runs): array
    {
        if (is_string($runs)) {
            return array_values(array_filter(array_map(trim(...), explode(',', $runs)), static fn (string $id): bool => $id !== ''));
        }

        return is_array($runs) ? array_values($runs) : [];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function comparisonRunRules(): array
    {
        return [
            'run_ids' => ['required', 'array', 'list', 'min:2', 'max:5'],
            'run_ids.*' => ['integer', 'distinct', Rule::exists(BenchmarkRun::class, 'id')],
        ];
    }

    protected function validateComparableRuns(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        foreach (BenchmarkRun::query()->whereKey((array) $this->input('run_ids', []))->get() as $benchmarkRun) {
            if (! $benchmarkRun->isComparable()) {
                $validator->errors()->add('run_ids', __('":name" has not finished, so it cannot be compared.', ['name' => $benchmarkRun->name]));
            }
        }
    }
}
