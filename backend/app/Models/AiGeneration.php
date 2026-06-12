<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiGeneration extends Model
{
    use AppliesTenantScope;

    protected $table = 'ai_generations';

    protected $fillable = [
        'tenant_id',
        'generation_id',
        'service',
        'prompt_version',
        'input_payload',
        'output',
        'tokens_input',
        'tokens_output',
        'cost_usd',
        'model',
        'status',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output'        => 'array',
        'tokens_input'  => 'int',
        'tokens_output' => 'int',
        'cost_usd'      => 'decimal:6',
    ];
}

