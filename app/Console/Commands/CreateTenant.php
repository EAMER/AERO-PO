<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProcurementAccess;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateTenant extends Command
{
    protected $signature = 'tenant:create {slug} {name} {email : Initial tenant administrator email}';

    protected $description = 'Create a company tenant and its first administrator with a generated password';

    public function handle(): int
    {
        $data = ['slug' => $this->argument('slug'), 'name' => $this->argument('name'), 'email' => strtolower($this->argument('email'))];
        $validator = Validator::make($data, ['slug' => ['required', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', Rule::unique('tenants')], 'name' => 'required|string|max:255', 'email' => 'required|email|max:255']);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }
        $password = Str::password(20);
        DB::transaction(function () use ($data, $password) {
            $tenant = Tenant::create(['slug' => $data['slug'], 'name' => $data['name']]);
            TenantContext::runAs($tenant, function () use ($tenant, $data, $password) {
                $admin = User::create(['name' => 'Tenant administrator', 'email' => $data['email'], 'password' => $password, 'role' => Role::TenantAdmin]);
                ProcurementAccess::audit($tenant, $admin, 'tenant.created', [], ['slug' => $tenant->slug, 'admin_id' => $admin->id]);
            });
        });
        $this->info('Tenant created: '.$data['slug'].'.'.config('tenancy.domain'));
        $this->line('Administrator: '.$data['email']);
        $this->line('Initial password: '.$password);
        $this->warn('Share the password privately and change it after first login.');

        return self::SUCCESS;
    }
}
