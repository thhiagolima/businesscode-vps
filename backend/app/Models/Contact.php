<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use AppliesTenantScope, HasFactory;

    protected $fillable = [
        'tenant_id',
        'contact_list_id',
        'name',
        'phone',
        'email',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }
}
