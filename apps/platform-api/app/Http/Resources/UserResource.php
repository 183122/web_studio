<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'emailVerified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'isPlatformAdmin' => (bool) $this->is_platform_admin,
            'twoFactorEnabled' => $this->two_factor_confirmed_at !== null,
        ];
    }
}
