<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

class ProcurementDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Demo vendors can only be seeded locally or in tests.');
        }
        foreach (Tenant::whereIn('slug', ['aero', 'demo'])->get() as $tenant) {
            TenantContext::runAs($tenant, function () {
                foreach ([['Example Parts A', 'parts-a@example.test', 'supplier', 'net'], ['Example Parts B', 'parts-b@example.test', 'supplier', 'net'], ['Example Parts C', 'parts-c@example.test', 'supplier', 'cia'], ['Example Forwarder', 'freight@example.test', 'freight_forwarder', 'net'], ['Example Courier', 'courier@example.test', 'courier', 'net']] as [$name, $email, $kind, $terms]) {
                    Vendor::firstOrCreate(['email' => $email, 'kind' => $kind], ['name' => $name, 'payment_terms' => $terms, 'is_active' => true, 'is_approved' => false]);
                }
            });
        }
    }
}
