<?php

namespace App\Http\Controllers\API\V1\Concerns;

trait ChecksTenant
{
    protected function ensureTenantOwns($model, ?string $tenantField = 'tenant_id'): void
    {
        $user = auth()->user();
        if ($user->role === 'superadmin') return;

        if ($model->{$tenantField} !== $user->tenant_id) {
            abort(403, 'Acesso negado.');
        }
    }
}
