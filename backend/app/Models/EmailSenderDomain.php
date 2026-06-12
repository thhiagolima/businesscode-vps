<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailSenderDomain extends Model
{
    use AppliesTenantScope, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'domain', 'infobip_domain_id', 'status',
        'dkim_selector', 'dkim_value', 'spf_value', 'return_path_value',
        'dkim_verified', 'spf_verified', 'return_path_verified',
        'tracking_opens', 'tracking_clicks',
        'last_verified_at', 'last_verification_error', 'verification_attempts',
        'created_by',
    ];

    protected $casts = [
        'dkim_verified'         => 'boolean',
        'spf_verified'          => 'boolean',
        'return_path_verified'  => 'boolean',
        'tracking_opens'        => 'boolean',
        'tracking_clicks'       => 'boolean',
        'last_verified_at'      => 'datetime',
        'verification_attempts' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function allRecordsVerified(): bool
    {
        return $this->dkim_verified && $this->spf_verified && $this->return_path_verified;
    }

    /**
     * True if the given email's @-part matches this domain (case-insensitive).
     */
    public function domainOfEmail(string $email): bool
    {
        $parts = explode('@', mb_strtolower(trim($email)));
        return count($parts) === 2 && $parts[1] === mb_strtolower($this->domain);
    }
}
