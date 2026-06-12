<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name'              => $name,
            'slug'              => Str::slug($name) . '-' . Str::random(4),
            'plan_id'           => Plan::factory(),
            'balance_cents'     => $this->faker->numberBetween(0, 100000),
            'billing_cycle_day' => $this->faker->numberBetween(1, 28),
            'status'            => 'trial',
            'trial_ends_at'     => now()->addDays(14),
        ];
    }
}
