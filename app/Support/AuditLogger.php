<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class AuditLogger
{
    public static function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null,
        array $meta = [],
        ?Request $request = null
    ): void {
        try {
            $user = Auth::user();
            $req = $request ?? request();

            AuditLog::create([
                'user_id' => $user?->id,
                'role' => $user?->role?->name,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description,
                'meta' => empty($meta) ? null : $meta,
                'ip_address' => $req?->ip(),
                'user_agent' => $req?->userAgent(),
            ]);
        } catch (Throwable $e) {
            // Avoid breaking primary flows if audit logging fails.
        }
    }
}
