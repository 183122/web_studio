<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        $row = [
            'organization_id' => $organizationId ?? $subject?->getAttribute('organization_id'),
            'workspace_id' => $workspaceId ?? $subject?->getAttribute('workspace_id'),
            'actor_id' => $actorId ?? $request?->user()?->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'metadata' => $this->redact($metadata) ?: null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        // Tamper evidence: each entry hashes its canonical snapshot together with the previous hash.
        return DB::transaction(function () use ($row) {
            $head = DB::table('audit_chain_head')->where('id', 1)->lockForUpdate()->first();
            $prev = $head?->last_hash;
            $data = self::canonical($row);
            $hash = hash('sha256', ($prev ?? '')."\n".$data);
            $log = AuditLog::create($row + ['prev_hash' => $prev, 'hash' => $hash, 'chain_data' => $data]);
            DB::table('audit_chain_head')->where('id', 1)->update(['last_hash' => $hash]);

            return $log;
        });
    }

    public static function canonical(array $row): string
    {
        $sort = function ($v) use (&$sort) {
            if (is_array($v)) {
                if (array_is_list($v)) {
                    return array_map($sort, $v);
                }
                ksort($v);

                return array_map($sort, $v);
            }

            return $v;
        };

        return json_encode($sort($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Walks the chain. Returns null when intact, else the id of the first broken entry and why.
     * organization_id / actor_id may legitimately become null later (FK nullOnDelete on purge).
     *
     * @return array{id: int, reason: string}|null
     */
    public static function verify(): ?array
    {
        $prev = null;
        $broken = null;
        DB::table('audit_logs')->whereNotNull('hash')->orderBy('id')->chunk(1000, function ($rows) use (&$prev, &$broken) {
            foreach ($rows as $r) {
                if ($r->prev_hash !== $prev) {
                    $broken = ['id' => $r->id, 'reason' => 'link mismatch (entry removed or reordered)'];

                    return false;
                }
                if (! hash_equals($r->hash, hash('sha256', ($prev ?? '')."\n".$r->chain_data))) {
                    $broken = ['id' => $r->id, 'reason' => 'hash mismatch (snapshot altered)'];

                    return false;
                }
                $snap = json_decode($r->chain_data, true);
                $meta = $r->metadata === null ? null : json_decode($r->metadata, true);
                foreach (['action', 'subject_type', 'subject_id', 'workspace_id', 'ip_address'] as $f) {
                    if ((string) ($snap[$f] ?? '') !== (string) ($r->{$f} ?? '')) {
                        $broken = ['id' => $r->id, 'reason' => "column {$f} differs from snapshot"];

                        return false;
                    }
                }
                foreach (['organization_id', 'actor_id'] as $f) {
                    if ($r->{$f} !== null && (string) $r->{$f} !== (string) ($snap[$f] ?? '')) {
                        $broken = ['id' => $r->id, 'reason' => "column {$f} differs from snapshot"];

                        return false;
                    }
                }
                if (self::canonical(['m' => $meta]) !== self::canonical(['m' => $snap['metadata'] ?? null])) {
                    $broken = ['id' => $r->id, 'reason' => 'metadata differs from snapshot'];

                    return false;
                }
                $prev = $r->hash;
            }
        });
        if ($broken === null) {
            $head = DB::table('audit_chain_head')->where('id', 1)->value('last_hash');
            if ($head !== $prev) {
                $broken = ['id' => 0, 'reason' => 'latest entries were removed (head mismatch)'];
            }
        }

        return $broken;
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
