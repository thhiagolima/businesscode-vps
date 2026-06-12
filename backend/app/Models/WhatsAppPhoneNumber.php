<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppPhoneNumber extends Model
{
    protected $fillable = ['phone_number_id', 'tenant_id', 'phone_display'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
