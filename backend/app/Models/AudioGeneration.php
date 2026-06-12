<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class AudioGeneration extends Model
{
    use AppliesTenantScope;

    protected $table = 'audio_generations';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'voice_id',
        'voice_name',
        'script',
        'audio_path',
        'audio_url',
        'duration_seconds',
        'characters_used',
        'cost_price',
        'sale_price',
        'credits_charged',
        'status',
        'error_message',
    ];

    protected $casts = [
        'cost_price'   => 'decimal:4',
        'sale_price'   => 'decimal:4',
        'duration_seconds' => 'int',
        'characters_used'  => 'int',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = auth()->user()?->tenant_id;
            }
        });
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
