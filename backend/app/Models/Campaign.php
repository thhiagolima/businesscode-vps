<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use AppliesTenantScope, HasFactory;

    // SECURITY: `tenant_id` is intentionally NOT mass-assignable.
    // Trait AppliesTenantScope sets it from auth context on creation; do not accept it from
    // client payloads. Background flows can still set it via forceFill().
    protected $fillable = [
        'name', 'type', 'content', 'subject', 'audio_url',
        'contact_list_id', 'strategy_locked', 'settings', 'scheduled_at',
        'estimated_contacts',
    ];

    protected $casts = [
        'strategy_locked'        => 'boolean',
        'settings'               => 'array',
        'scheduled_at'           => 'datetime',
        'started_at'             => 'datetime',
        'completed_at'           => 'datetime',
        'sent_count'             => 'integer',
        'failed_count'           => 'integer',
        'estimated_contacts'     => 'integer',
        'unit_cents_at_dispatch' => 'integer',
        'reserved_cents'         => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(CampaignDispatch::class);
    }
}
