<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignDispatch extends Model
{
    use AppliesTenantScope, HasFactory;

    protected $fillable = [
        'tenant_id','campaign_id','contact_id','status','phone','message_content',
        'external_message_id','sent_at','delivered_at','failed_at','error_message',
        'unsubscribe_token','unsubscribe_consumed_at',
    ];

    protected $casts = [
        'sent_at'                 => 'datetime',
        'delivered_at'            => 'datetime',
        'failed_at'               => 'datetime',
        'unsubscribe_consumed_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
