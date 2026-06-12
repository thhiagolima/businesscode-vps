<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'tenant_id'       => Tenant::factory(),
            // Auto-create a contact list within the same tenant. The closure receives
            // the already-resolved attributes so `tenant_id` is concrete by this point.
            'contact_list_id' => function (array $attrs) {
                return ContactList::firstOrCreate(
                    ['tenant_id' => $attrs['tenant_id'], 'name' => 'Default'],
                    ['contact_count' => 0],
                )->id;
            },
            'name'   => $this->faker->name(),
            'phone'  => '+5511' . $this->faker->unique()->numerify('9########'),
            'email'  => $this->faker->unique()->safeEmail(),
            'status' => 'active',
            'meta'   => null,
        ];
    }
}
