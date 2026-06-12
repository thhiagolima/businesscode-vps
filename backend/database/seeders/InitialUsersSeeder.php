<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialUsersSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::where('slug', 'free')->first() ?? Plan::first();

        $this->seedAccount(
            tenantSlug: 'admin',
            tenantName: 'Administração',
            balanceCents: 99999900,
            email: 'admin@businesscode.com.br',
            name: 'Super Admin',
            password: '!Nextel3390',
            role: 'superadmin',
            planId: $plan?->id,
        );

        $this->seedAccount(
            tenantSlug: 'parceriapedro',
            tenantName: 'Parceria Pedro',
            balanceCents: 100000,
            email: 'parceriapedro@businesscode.com.br',
            name: 'Parceria Pedro',
            password: '!@Nextel3390',
            role: 'user',
            planId: $plan?->id,
        );
    }

    private function seedAccount(
        string $tenantSlug,
        string $tenantName,
        int $balanceCents,
        string $email,
        string $name,
        string $password,
        string $role,
        ?int $planId,
    ): void {
        $tenant = Tenant::updateOrCreate(
            ['slug' => $tenantSlug],
            [
                'name'          => $tenantName,
                'plan_id'       => $planId,
                'balance_cents' => $balanceCents,
                'status'        => 'active',
            ]
        );

        TenantChannel::provisionDefaults($tenant->id);

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name'                 => $name,
            'password'             => Hash::make($password),
            'tenant_id'            => $tenant->id,
            'role'                 => $role,
            'email_verified_at'    => $user->email_verified_at ?? now(),
            'lgpd_consented_at'    => $user->lgpd_consented_at ?? now(),
            'lgpd_consent_version' => $user->lgpd_consent_version ?? config('business.terms_version', '1.0.0'),
            'lgpd_consent_ip'      => $user->lgpd_consent_ip ?? '127.0.0.1',
        ])->save();
    }
}
