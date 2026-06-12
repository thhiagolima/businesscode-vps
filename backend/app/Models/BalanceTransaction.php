<?php
namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BalanceTransaction — financial audit trail.
 *
 * Note: `tenant_id` is nullable since migration
 * 2026_05_27_000008. When a tenant is hard-deleted, the FK
 * `ON DELETE SET NULL` keeps the row alive for accounting/LGPD
 * retention (Red Team finding #20). The row becomes an orphan
 * record visible only to superadmin (`withoutGlobalScopes()`);
 * per-tenant queries naturally exclude it via `WHERE tenant_id = X`.
 */
class BalanceTransaction extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'type', 'amount_cents', 'balance_after_cents',
        'reference_type', 'reference_id', 'executed_by_user_id', 'monthly_cycle_key',
        'description', 'meta',
    ];

    protected $casts = [
        'amount_cents'        => 'integer',
        'balance_after_cents' => 'integer',
        'reference_id'        => 'integer',
        'executed_by_user_id' => 'integer',
        'meta'                => 'array',
    ];

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
