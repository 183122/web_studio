<?php

namespace App\Domain\Data;

use App\Models\AppUser;

/** Who performs a data operation. `role` is the generated-app role used for permission checks. */
final class Actor
{
    public function __construct(
        public readonly string $type,      // user | public | member | admin | api_key | workflow
        public readonly ?string $id,
        public readonly string $role,      // public | member | admin
        public readonly ?string $ip = null,
        public readonly ?string $userId = null,
        public readonly ?string $appUserId = null,
    ) {}

    /** End user of a published app (signed in), or an anonymous visitor. */
    public static function appUser(?AppUser $user, ?string $ip): self
    {
        return $user ? new self($user->role, $user->id, $user->role, $ip, null, $user->id) : new self('public', null, 'public', $ip);
    }

    /** Platform workspace member working in the studio: full access to the project's app data. */
    public static function studio(string $userId, ?string $ip): self
    {
        return new self('user', $userId, 'admin', $ip, $userId);
    }

    public static function previewRole(string $role, string $userId, ?string $ip): self
    {
        return new self($role, $userId, $role, $ip, $userId);
    }

    public static function workflow(string $workflowId): self
    {
        return new self('workflow', $workflowId, 'admin');
    }

    public static function apiKey(string $keyId, string $role, ?string $ip): self
    {
        return new self('api_key', $keyId, $role, $ip);
    }
}
