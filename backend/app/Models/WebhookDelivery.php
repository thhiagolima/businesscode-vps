<?php
namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use AppliesTenantScope;

    public $timestamps = false;

    protected $fillable = [
        'outbound_webhook_id', 'tenant_id', 'event',
        'request_body', 'response_status', 'response_body',
        'duration_ms', 'attempt_number', 'error_message', 'fired_at',
    ];

    protected $casts = [
        'request_body' => 'array',
        'fired_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(OutboundWebhook::class, 'outbound_webhook_id');
    }
}
