<?php

namespace Tests\Feature\Security;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_not_mass_assignable_via_create(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'trial',
        ]);

        $user = User::create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id,
            'role' => 'superadmin', // payload-forged
        ]);

        // role must default to 'user' or null — never 'superadmin' from mass assignment.
        $this->assertNotSame('superadmin', $user->fresh()->role);
    }

    public function test_tenant_id_is_not_mass_assignable_via_create(): void
    {
        $a = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'trial']);
        $b = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'trial']);

        // attempting to assign tenant via mass assignment must be ignored.
        $user = (new User())->forceFill([
            'name' => 'Legit',
            'email' => 'legit@example.com',
            'password' => bcrypt('secret123'),
            'tenant_id' => $a->id,
        ]);
        $user->save();

        $user->fill(['tenant_id' => $b->id]); // payload-forged
        $user->save();

        $this->assertSame($a->id, $user->fresh()->tenant_id);
    }

    public function test_role_is_not_mass_assignable_via_fill_and_save(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'trial']);

        $user = (new User())->forceFill([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id,
            'role' => 'user',
        ]);
        $user->save();

        $user->fill(['role' => 'superadmin']); // simulates update($request->all())
        $user->save();

        $this->assertSame('user', $user->fresh()->role);
    }

    public function test_force_fill_still_allows_internal_assignment(): void
    {
        // Internal legitimate path (AuthController::register) must still work via forceFill.
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'trial']);

        $user = (new User())->forceFill([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
        ]);
        $user->save();

        $this->assertSame('admin', $user->fresh()->role);
        $this->assertSame($tenant->id, $user->fresh()->tenant_id);
    }
}
