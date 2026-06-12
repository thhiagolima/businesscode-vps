<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'price_monthly',
        'price_annual',
        'max_contacts',
        'max_campaigns',
        'features',
        'rate_limit_sms_per_min', 'rate_limit_voice_per_min', 'rate_limit_email_per_min',
        'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_hours_timezone',
        'included_balance_cents', 'sale_cents_overrides',
        'whatsapp_setup_fee_cents', 'whatsapp_billed_separately',
        'listed',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_annual' => 'decimal:2',
        'max_contacts' => 'integer',
        'max_campaigns' => 'integer',
        'features' => 'array',
        'rate_limit_sms_per_min'      => 'integer',
        'rate_limit_voice_per_min'    => 'integer',
        'rate_limit_email_per_min'    => 'integer',
        'quiet_hours_enabled'         => 'boolean',
        'included_balance_cents'      => 'integer',
        'sale_cents_overrides'        => 'array',
        'whatsapp_setup_fee_cents'    => 'integer',
        'whatsapp_billed_separately'  => 'boolean',
        'listed'                      => 'boolean',
    ];

    public function priceFor(string $cycle): float
    {
        if ($cycle === 'annual') {
            return (float) ($this->price_annual ?? ((float) $this->price_monthly * 12 * 0.8));
        }
        return (float) $this->price_monthly;
    }

    public function monthlyEquivalentFor(string $cycle): float
    {
        if ($cycle === 'annual') {
            return round($this->priceFor('annual') / 12, 2);
        }
        return (float) $this->price_monthly;
    }
}
