<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The two fields an admin may change on an existing Seerr request; the
 * controller re-reads every other field from Seerr before the PUT.
 */
class UpdateSeerrRequestRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'integer'],
            'root_folder' => ['required', 'string'],
        ];
    }
}
