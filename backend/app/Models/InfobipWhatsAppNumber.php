<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InfobipWhatsAppNumber extends Model
{
    protected $table = 'infobip_whatsapp_numbers';
    protected $fillable = [
        'sender', 'number', 'display_name', 'tenant_id', 'status', 'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
