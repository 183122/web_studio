<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Writes append-only audit records. Metadata must never contain secrets or tokens. */
class AuditLogger
{
    private const REDACT = ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key'];

    public function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        ?string $organizationId = null,
        ?string $workspaceId = null,
        ?string $actorId = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'organization_id' => $organizationId ?? $subject?->getAttribute('organization_id'),
            'workspace_id' => $workspaceId ?? $subject?->getAttribute('workspace_id'),
            'actor_id' => $actorId ?? $request?->user()?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'metadata' => $this->redact($metadata) ?: null,
        ]);
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && Str::contains(strtolower($key), self::REDACT)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
