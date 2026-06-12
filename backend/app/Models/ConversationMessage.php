<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    use AppliesTenantScope;

    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'tenant_id', 'direction', 'sender_type', 'sender_id',
        'type', 'content', 'media_url', 'template_name', 'external_message_id',
        'status', 'error_message', 'metadata', 'created_at', 'sent_at',
        'delivered_at', 'read_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'created_at'   => 'datetime',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
