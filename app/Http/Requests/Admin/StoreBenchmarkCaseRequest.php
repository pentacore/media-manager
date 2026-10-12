<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\BenchmarkCaseValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
