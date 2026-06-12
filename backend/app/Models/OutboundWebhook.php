<?php
namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class OutboundWebhook extends Model
{
    use AppliesTenantScope;

    protected $fillable = ['tenant_id', 'url', 'secret', 'events', 'is_active'];

    protected $casts = ['events' => 'array', 'is_active' => 'boolean'];
}
