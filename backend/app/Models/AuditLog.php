<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditLog extends Model
{
    use AppliesTenantScope;
    public $timestamps = false;

    protected $fillable = ['user_id', 'tenant_id', 'action', 'resource', 'resource_id', 'ip_address', 'user_agent', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    /**
     * Append-only enforcement (LGPD art. 37): audit rows must never be updated.
     * For prod-grade tamper resistance, also add a MySQL trigger that aborts
     * UPDATE/DELETE at the DB level — this Eloquent guard is defense-in-depth.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('AuditLog is append-only and cannot be updated.');
        });
        static::deleting(function () {
            throw new \RuntimeException('AuditLog is append-only and cannot be deleted.');
        });
    }

    /**
     * Record an audit event. Captures user, tenant, IP and User-Agent so the
     * log satisfies LGPD art. 37 (registro das operações de tratamento).
     * Accepts explicit user/tenant so it also works from background jobs
     * where auth() is not available.
     */
    public static function record(
        string $action,
        ?string $resource = null,
        ?int $resourceId = null,
        ?array $metadata = null,
        ?int $userId = null,
        ?int $tenantId = null
    ): void {
        try {
            $request = request();
            $userAgent = $request && $request->userAgent() ? substr((string) $request->userAgent(), 0, 500) : null;

            static::forceCreate([
                'user_id'     => $userId ?? auth()->id(),
                'tenant_id'   => $tenantId ?? auth()->user()?->tenant_id,
                'action'      => $action,
                'resource'    => $resource,
                'resource_id' => $resourceId,
                'ip_address'  => $request?->ip(),
                'user_agent'  => $userAgent,
                'metadata'    => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit.log_failed', ['error' => $e->getMessage()]);
        }
    }
}
