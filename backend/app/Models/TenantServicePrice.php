<?php
namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantServicePrice extends Model
{
    use AppliesTenantScope;

    protected $fillable = ['tenant_id', 'service', 'sale_cents', 'sale_micros', 'reason', 'created_by'];

    protected $casts = [
        'sale_cents'  => 'integer',
        'sale_micros' => 'integer',
    ];

    public function effectiveSaleMicros(): int
    {
        return $this->sale_micros > 0 ? $this->sale_micros : $this->sale_cents * ServicePrice::MICROS_PER_CENT;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
