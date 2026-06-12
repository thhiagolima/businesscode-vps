<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageOptOut extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'channel', 'identifier', 'identifier_hash', 'reason', 'source_dispatch_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sourceDispatch(): BelongsTo
    {
        return $this->belongsTo(MessageDispatch::class, 'source_dispatch_id');
    }

    public static function hashFor(string $identifier): string
    {
        return hash('sha256', mb_strtolower(trim($identifier)));
    }
}
