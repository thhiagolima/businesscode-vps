<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id',
        'contact_list_id',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'error_message',
        'mapping',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'mapping' => 'array',
    ];

    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }
}
