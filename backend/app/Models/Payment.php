<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory, \App\Models\Traits\AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'subscription_id', 'mp_payment_id', 'type', 'status',
        'payment_method', 'amount', 'net_amount', 'credits_purchased',
        'pix_qr_code', 'pix_qr_code_base64', 'pix_expiration',
        'boleto_url', 'boleto_barcode', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'credits_purchased' => 'integer',
        'pix_expiration' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function subscription() { return $this->belongsTo(Subscription::class); }
}
