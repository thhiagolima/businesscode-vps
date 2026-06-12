<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FunnelEdge extends Model
{
    protected $fillable = ['funnel_id', 'edge_id', 'source_node_id', 'target_node_id', 'label'];

    public function funnel()
    {
        return $this->belongsTo(Funnel::class);
    }
}
