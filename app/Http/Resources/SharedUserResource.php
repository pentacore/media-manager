<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * Minimal user payload shared via Inertia on every page.
 *
 * Exposes ONLY the fields the frontend actually reads from `auth.user`.
 * Sharing the raw User model would leak attributes like `password`,
 * `remember_token`, two-factor secrets, etc., to every Inertia page.
 *
 * @mixin User
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'name' => 'string',
    'email' => 'string',
    'email_verified_at' => 'string | null',
    'role' => "'admin' | 'member' | 'viewer'",
    'avatar_url' => 'string | null',
])]
class SharedUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'role' => $this->role->value,
            'avatar_url' => $this->avatar_url,
        ];
    }
}
