<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        TenantContext::bypass(function () {
            foreach (['aero' => 'Aero Contractors', 'demo' => 'Demo Airline'] as $slug => $name) {
                $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name]);

                foreach (Role::cases() as $role) {
                    if ($role === Role::SuperAdmin) {
                        continue;
                    }
                    User::firstOrCreate(
                        ['tenant_id' => $tenant->id, 'email' => "{$role->value}@{$slug}.test"],
                        ['name' => ucwords(str_replace('_', ' ', $role->value)), 'password' => 'password', 'role' => $role]
                    );
                }
            }
        });
    }
}
