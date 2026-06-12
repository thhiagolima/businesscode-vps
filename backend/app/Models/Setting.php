<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'group',
        'key',
        'value',
        'type',
    ];

    protected $casts = [
        'value' => 'string',
        'type'  => 'string',
    ];
}

