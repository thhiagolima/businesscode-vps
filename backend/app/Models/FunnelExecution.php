<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class FunnelExecution extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id',
        'funnel_id',
        'contact_id',
        'conversation_id',
        'current_node_id',
        'status',
        'wait_until',
        'last_message_id',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'wait_until' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function funnel()
    {
        return $this->belongsTo(Funnel::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
