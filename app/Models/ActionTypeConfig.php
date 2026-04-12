<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ActionTypeConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'label', 'description', 'requires_approval', 'is_enabled'])]
class ActionTypeConfig extends Model
{
    /** @use HasFactory<ActionTypeConfigFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
