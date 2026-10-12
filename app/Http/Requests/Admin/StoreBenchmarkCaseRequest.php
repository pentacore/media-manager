<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\BenchmarkCaseValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * `input` and `scoring` are overridden because typefinder cannot render their
 * wildcard (`history.*`, `expected.*`) rules.
 */
#[TypefinderOverrides([
    'input' => '{ prompt: string; history?: Array<{ role: \'user\' | \'assistant\'; content: string }> | null; mode?: \'executive\' | \'advisory\' | null; event_key?: string | null; providers?: string[] }',
    'scoring' => '{ type: \'actions\' | \'prices\' | \'judge\'; expected?: Array<Record<string, unknown>> | null; tolerance_percent?: number | null; rubric_note?: string | null; pass_threshold?: number | null }',
])]
class StoreBenchmarkCaseRequest extends FormRequest
{
    use BenchmarkCaseValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->benchmarkCaseRules();
    }
}
