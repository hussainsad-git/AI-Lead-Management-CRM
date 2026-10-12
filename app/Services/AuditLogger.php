<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail for security-relevant and sensitive actions. */
class AuditLogger
{
    private const REDACT = ['password', 'remember_token', 'access_token', 'app_secret', 'token', 'secret'];

    public function log(string $action, ?Model $subject = null, array $changes = [], array $meta = []): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes ? $this->redact($changes) : null,
            'meta' => $meta ? $this->redact($meta) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /** Build an old/new diff for dirty attributes of a model about to be saved (call before save). */
    public function diff(Model $model): array
    {
        $out = [];
        foreach ($model->getDirty() as $key => $new) {
            if (in_array($key, ['updated_at'], true)) {
                continue;
            }
            $out[$key] = ['old' => $model->getOriginal($key), 'new' => $new];
        }

        return $out;
    }

    private function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }

        return $data;
    }
}
