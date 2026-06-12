<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FunnelNode extends Model
{
    protected $fillable = ['funnel_id', 'node_id', 'type', 'label', 'config', 'position_x', 'position_y'];

    protected $casts = ['config' => 'array', 'position_x' => 'float', 'position_y' => 'float'];

    public function funnel()
    {
        return $this->belongsTo(Funnel::class);
    }
}
