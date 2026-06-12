<?php

namespace Database\Factories;

use App\Models\Funnel;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Funnel>
 */
class FunnelFactory extends Factory
{
    protected $model = Funnel::class;

    public function definition(): array
    {
        return [
            'tenant_id'   => Tenant::factory(),
            'name'        => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'status'      => 'active',
            'is_default'  => false,
            'triggers'    => null,
        ];
    }
}
