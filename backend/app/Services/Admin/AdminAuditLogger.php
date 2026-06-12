<?php

namespace App\Services\Admin;

use App\Models\AuditLog;

class AdminAuditLogger
{
    public static function log(
        string $resource,
        string $verb,
        int $targetTenantId,
        ?int $targetResourceId = null,
        ?array $extra = null,
    ): void {
        $action = "admin.{$resource}.{$verb}";
        $adminId = auth()->id();

        AuditLog::record(
            $action,
            ucfirst($resource),
            $targetResourceId,
            array_merge(['target_tenant_id' => $targetTenantId], $extra ?? []),
            $adminId,
            $targetTenantId,
        );
    }
}
