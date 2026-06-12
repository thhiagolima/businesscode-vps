<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Funnel extends Model
{
    use AppliesTenantScope, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'description', 'status', 'is_default', 'triggers'];

    protected $casts = ['is_default' => 'boolean', 'triggers' => 'array'];

    public function nodes()
    {
        return $this->hasMany(FunnelNode::class);
    }

    public function edges()
    {
        return $this->hasMany(FunnelEdge::class);
    }

    public function executions()
    {
        return $this->hasMany(FunnelExecution::class);
    }
}
