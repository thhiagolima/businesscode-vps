<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignDispatch>
 */
class CampaignDispatchFactory extends Factory
{
    protected $model = CampaignDispatch::class;

    public function definition(): array
    {
        return [
            'tenant_id'           => Tenant::factory(),
            'campaign_id'         => Campaign::factory(),
            'contact_id'          => null,
            'status'              => 'pending',
            'phone'               => $this->faker->e164PhoneNumber(),
            'message_content'     => $this->faker->sentence(),
            'external_message_id' => null,
            'sent_at'             => null,
            'delivered_at'        => null,
            'failed_at'           => null,
            'error_message'       => null,
        ];
    }
}
