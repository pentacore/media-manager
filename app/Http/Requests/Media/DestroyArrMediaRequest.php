<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class DestroyArrMediaRequest extends FormRequest
{
    /**
     * The connection the title page was rendered from. Media ids overlap
     * between instances, so the delete is pinned to it and never falls back
     * to "the active one".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_connection_id' => ['required', 'integer', 'min:1'],
            'delete_files' => ['sometimes', 'boolean'],
        ];
    }
}
