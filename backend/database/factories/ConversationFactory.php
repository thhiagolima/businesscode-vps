<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'tenant_id'       => Tenant::factory(),
            'contact_id'      => null,
            'phone'           => '+5511' . $this->faker->unique()->numerify('9########'),
            'channel'         => 'whatsapp',
            'status'          => 'open',
            'assigned_to'     => null,
            'last_message_at' => now(),
            'unread_count'    => 0,
            'metadata'        => null,
        ];
    }
}
