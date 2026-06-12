<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

trait AppliesTenantScope
{
    /**
     * Laravel convention: methods named `bootTraitName` are called automatically
     * during the model boot, even when the consumer class defines its own `booted()`.
     * Renaming from `booted()` to `bootAppliesTenantScope()` ensures the scope is
     * always installed, even on models like AuditLog that have their own `booted()`.
     */
    protected static function bootAppliesTenantScope(): void
    {
        static::addGlobalScope('tenant', function (Builder $q) {
            if (auth()->check()) {
                $user = auth()->user();
                // Superadmin vê todos os registros de todos os tenants
                if ($user->role !== 'superadmin') {
                    $q->where('tenant_id', $user->tenant_id);
                }
            } elseif ($tenantId = \App\Services\TenantContext::get()) {
                $q->where('tenant_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            // Only auto-fill tenant_id when the caller did not provide it at all.
            // If `tenant_id` was explicitly set (including to null for pool/global rows),
            // preserve the caller's intent.
            if (! array_key_exists('tenant_id', $model->getAttributes())) {
                if (auth()->check()) {
                    $model->tenant_id = auth()->user()->tenant_id;
                } elseif ($tenantId = \App\Services\TenantContext::get()) {
                    $model->tenant_id = $tenantId;
                }
            }
        });
    }
}
