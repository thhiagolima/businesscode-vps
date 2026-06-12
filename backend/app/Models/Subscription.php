<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    use \App\Models\Traits\AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'plan_id', 'billing_cycle', 'mp_subscription_id', 'mp_payer_id',
        'status', 'payment_method', 'current_period_start', 'current_period_end',
        'price', 'discount_amount', 'coupon_id', 'cancelled_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function coupon() { return $this->belongsTo(Coupon::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'authorized']);
    }
}
