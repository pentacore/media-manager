<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\AiUsage\Pricing\CatalogModelBrowser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCatalogModelPricesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(resolve(CatalogModelBrowser::class)->providers())],
            'models' => ['required', 'array', 'min:1', 'max:100'],
            'models.*' => ['required', 'string', 'max:255', 'distinct'],
        ];
    }
}
