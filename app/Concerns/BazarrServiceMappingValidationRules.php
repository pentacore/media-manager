<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait BazarrServiceMappingValidationRules
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function bazarrServiceMappingRules(): array
    {
        $isBazarr = $this->input('type') === ServiceType::Bazarr->value;

        return [
            'sonarr_connection_id' => [Rule::excludeUnless($isBazarr), 'nullable', 'integer', 'min:1'],
            'radarr_connection_id' => [Rule::excludeUnless($isBazarr), 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('type') !== ServiceType::Bazarr->value) {
                    return;
                }

                $mappingIds = [];

                foreach (BazarrServiceRole::cases() as $role) {
                    $field = $role->value.'_connection_id';
                    $connectionId = $this->integer($field);

                    if ($connectionId > 0) {
                        $mappingIds[$role->value] = $connectionId;
                    }
                }

                if ($mappingIds === []) {
                    $message = 'Select a Sonarr or Radarr connection.';
                    $validator->errors()->add('sonarr_connection_id', $message);
                    $validator->errors()->add('radarr_connection_id', $message);

                    return;
                }

                foreach (BazarrServiceRole::cases() as $role) {
                    $connectionId = $mappingIds[$role->value] ?? null;

                    if ($connectionId === null) {
                        continue;
                    }

                    $hasExpectedType = ServiceConnection::query()
                        ->whereKey($connectionId)
                        ->where('type', $role->serviceType())
                        ->exists();

                    if (! $hasExpectedType) {
                        $validator->errors()->add(
                            $role->value.'_connection_id',
                            'The selected connection has the wrong service type.',
                        );
                    }
                }
            },
        ];
    }
}
