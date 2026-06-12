<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use AppliesTenantScope, HasFactory;

    protected $fillable = [
        'tenant_id', 'contact_id', 'phone', 'channel', 'status',
        'assigned_to', 'last_message_at', 'unread_count', 'metadata',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count'    => 'integer',
        'metadata'        => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class);
    }
}
