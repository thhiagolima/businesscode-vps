<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiPersona extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'bot_name', 'tone', 'company_name',
        'products_services', 'business_rules', 'special_instructions',
        'working_hours', 'status', 'rejection_reason',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
