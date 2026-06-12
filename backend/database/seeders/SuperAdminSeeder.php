<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('SUPERADMIN_EMAIL');
        $password = env('SUPERADMIN_PASSWORD');

        if (! $email || ! $password) {
            return;
        }

        // Garante que o superadmin tenha um tenant próprio (créditos ilimitados)
        $plan = Plan::first();

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'admin'],
            [
                'name'          => 'Administração',
                'plan_id'       => $plan?->id,
                'balance_cents' => 99999900,
                'status'        => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'      => 'Super Admin',
                'password'  => Hash::make($password),
                'role'      => 'superadmin',
                'tenant_id' => $tenant->id,
            ]
        );
    }
}
