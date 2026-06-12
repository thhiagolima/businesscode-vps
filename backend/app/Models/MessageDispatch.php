<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Database\Factories\MessageDispatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageDispatch extends Model
{
    /** @use HasFactory<MessageDispatchFactory> */
    use AppliesTenantScope, HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'channel', 'source', 'to', 'from', 'from_name', 'reply_to', 'subject',
        'content', 'audio_url', 'provider', 'external_message_id', 'status',
        'cost_cents', 'sale_cents', 'charged_cents',
        'cost_micros', 'sale_micros',
        'idempotency_key', 'idempotency_payload_hash',
        'unsubscribe_token', 'unsubscribe_consumed_at', 'error_code', 'error_message',
        'scheduled_for', 'sent_at', 'delivered_at', 'failed_at', 'meta', 'variables',
        // Voice-specific call tracking (populated by Infobip delivery webhook)
        'answered_at', 'ended_at', 'call_duration_seconds', 'voice_status',
        // Email tracking (populated by Infobip email-events webhook)
        'opened_at', 'first_clicked_at', 'bounced_at', 'bounce_type',
        'complaint_at', 'unsubscribed_at',
    ];

    protected $casts = [
        'meta'                    => 'array',
        'variables'               => 'array',
        'scheduled_for'           => 'datetime',
        'sent_at'                 => 'datetime',
        'delivered_at'            => 'datetime',
        'failed_at'               => 'datetime',
        'unsubscribe_consumed_at' => 'datetime',
        'cost_cents'              => 'integer',
        'sale_cents'              => 'integer',
        'charged_cents'           => 'integer',
        'cost_micros'             => 'integer',
        'sale_micros'             => 'integer',
        // Voice call tracking
        'answered_at'             => 'datetime',
        'ended_at'                => 'datetime',
        'call_duration_seconds'   => 'integer',
        // Email tracking
        'opened_at'               => 'datetime',
        'first_clicked_at'        => 'datetime',
        'bounced_at'              => 'datetime',
        'complaint_at'            => 'datetime',
        'unsubscribed_at'         => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
