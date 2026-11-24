<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Override;

/**
 * The schedule form's live estimate: the task, tags and judge of a schedule
 * that may not be saved yet. The cases and tier models are resolved on the
 * server, as a launch resolves them, so a task with any number of tier
 * models gets an estimate.
 */
class EstimateBenchmarkScheduleRequest extends StoreBenchmarkScheduleRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    #[Override]
    public function rules(): array
    {
        return Arr::only(parent::rules(), ['task', 'case_tags', 'case_tags.*', 'judge', 'judge.provider', 'judge.model', 'judge.reasoning']);
    }
}
