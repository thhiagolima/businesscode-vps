<?php

namespace Database\Factories;

use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageDispatch>
 */
class MessageDispatchFactory extends Factory
{
    protected $model = MessageDispatch::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => Tenant::factory(),
            'user_id'       => User::factory(),
            'channel'       => 'sms',
            'source'        => 'api',
            'to'            => '+5511999999999',
            'from'          => 'TestSender',
            'content'       => $this->faker->sentence(),
            'provider'      => 'twilio',
            'status'        => 'queued',
            'cost_cents'    => 8,
            'sale_cents'    => 15,
            'charged_cents' => 0,
        ];
    }
}
