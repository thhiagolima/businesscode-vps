<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactList extends Model
{
    use AppliesTenantScope;

    protected $fillable = ['tenant_id', 'name', 'description', 'contact_count'];

    protected $casts = [
        'contact_count' => 'integer',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
