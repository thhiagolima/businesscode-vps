<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiContentModel extends Model
{
    use AppliesTenantScope;

    protected $table = 'ai_content_models';

    protected $fillable = [
        'tenant_id',
        'name',
        'channel',
        'content',
        'briefing',
    ];

    protected $casts = [
        'briefing' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = auth()->user()?->tenant_id;
            }
        });
    }
}
