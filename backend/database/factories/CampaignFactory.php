<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'tenant_id'          => Tenant::factory(),
            'name'               => $this->faker->sentence(3),
            'type'               => $this->faker->randomElement(['sms', 'voice', 'email']),
            'status'             => 'draft',
            'content'            => $this->faker->paragraph(),
            'subject'            => null,
            'audio_url'          => null,
            'contact_list_id'    => null,
            'strategy_locked'    => false,
            'settings'           => [],
            'scheduled_at'       => null,
            'estimated_contacts' => 0,
        ];
    }
}
