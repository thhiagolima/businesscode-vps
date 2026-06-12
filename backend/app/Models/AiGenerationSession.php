<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiGenerationSession extends Model
{
    use AppliesTenantScope;

    protected $table = 'ai_generation_sessions';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'channel',
        'status',
        'briefing',
        'variations',
        'selected_variation_id',
        'expires_at',
    ];

    protected $casts = [
        'briefing'   => 'array',
        'variations' => 'array',
        'expires_at' => 'datetime',
    ];
}

