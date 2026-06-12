<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPrompt extends Model
{
    protected $table = 'ai_prompts';
    protected $fillable = [
        'service',
        'version',
        'system_prompt',
        'user_template',
        'model',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function activeFor(string $service): ?self
    {
        return static::query()
            ->where('service', $service)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }
}

