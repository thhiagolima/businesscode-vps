<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElevenLabsVoice extends Model
{
    use HasFactory;

    protected $table = 'elevenlabs_voices';

    protected $fillable = [
        'voice_id',
        'name',
        'category',
        'gender',
        'accent',
        'language',
        'preview_url',
        'labels',
        'is_active',
    ];

    protected $casts = [
        'labels' => 'array',
        'is_active' => 'boolean',
    ];
}
