<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name'                   => $this->faker->word() . ' Plan',
            'slug'                   => $this->faker->unique()->slug(2),
            'listed'                 => true,
            'price_monthly'          => 0,
            'price_annual'           => null,
            'included_balance_cents' => $this->faker->numberBetween(0, 50000),
            'max_contacts'           => 500,
            'max_campaigns'          => 10,
            'features'               => ['channels' => ['sms', 'voice']],
        ];
    }
}
