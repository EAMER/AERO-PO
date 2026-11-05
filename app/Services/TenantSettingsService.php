<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\KpiDefinition;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProcurementAccess as Access;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TenantSettingsService
{
    public const METRICS = ['average_cycle_days', 'open_orders', 'closed_orders', 'rejection_rate'];

    public function rolesFor(string $stage, bool $isAmo = false): array
    {
        return match ($stage) {
            'evaluation' => $isAmo ? [Role::Tlm, Role::Hamo, Role::TechnicalDirector] : [Role::Tlm, Role::TechnicalDirector],
            'cfo' => [Role::Cfo],
            'freight' => [Role::Tlm],
            'payment_cia', 'payment_net' => [Role::CostControl, Role::Tlm, Role::TechnicalDirector, Role::InternalAudit, Role::Payables, Role::Treasury],
            default => throw new InvalidArgumentException('Unknown approval stage.'),
        };
    }

    public function approver(string $stage, Role $role): ?int
    {
        $id = data_get(TenantContext::get()?->settings, 'approvers.'.$stage.'.'.$role->value);
        if (! $id) {
            return null;
        }
        $user = User::whereKey($id)->where('is_active', true)->where('role', $role)->first();
        if (! $user) {
            throw new InvalidArgumentException('The configured '.$role->value.' approver is inactive or no longer holds the required role.');
        }

        return $user->id;
    }

    public function saveApprovers(User $actor, array $assignments): void
    {
        Access::roles($actor);
        foreach ($assignments as $stage => $roles) {
            $allowed = $this->rolesFor($stage, true);
            foreach ($roles as $role => $userId) {
                $roleEnum = Role::tryFrom($role);
                if (! $roleEnum || ! in_array($roleEnum, $allowed, true)) {
                    throw new InvalidArgumentException('Invalid role for this approval stage.');
                }
                if ($userId && ! User::whereKey($userId)->where('role', $role)->where('is_active', true)->exists()) {
                    throw new InvalidArgumentException('Choose an active approver in this tenant with the matching role.');
                }
            }
        }
        $this->save($actor, 'approvers', $assignments);
    }

    public function saveSlas(User $actor, array $statusHours, array $approvalHours): void
    {
        Access::roles($actor, Role::Tlm);
        foreach ($statusHours as $status => $hours) {
            if (! PurchaseOrderStatus::tryFrom($status)) {
                throw new InvalidArgumentException('Unknown workflow status.');
            }
            $this->validateHours($hours);
        }
        foreach ($approvalHours as $stage => $hours) {
            $this->rolesFor($stage);
            $this->validateHours($hours);
        }
        $this->save($actor, 'sla_hours', $statusHours);
        $this->save($actor, 'approval_sla_hours', $approvalHours);
    }

    public function defineKpi(User $actor, array $data): KpiDefinition
    {
        Access::roles($actor, Role::Tlm);
        if (! in_array($data['metric'], self::METRICS, true) || ! in_array($data['direction'], ['at_most', 'at_least'], true) || ! is_numeric($data['target']) || $data['target'] < 0) {
            throw new InvalidArgumentException('Choose a supported metric, comparison, and non-negative target.');
        }

        return DB::transaction(function () use ($actor, $data) {
            $kpi = KpiDefinition::firstOrNew(['metric' => $data['metric']]);
            $before = $kpi->getAttributes();
            $kpi->fill(['name' => $data['name'], 'target' => $data['target'], 'direction' => $data['direction'], 'defined_by' => $actor->id])->save();
            Access::audit($kpi, $actor, 'kpi.defined', $before, $kpi->getAttributes());

            return $kpi;
        });
    }

    private function save(User $actor, string $key, array $value): void
    {
        DB::transaction(function () use ($actor, $key, $value) {
            $tenant = Tenant::whereKey(TenantContext::id())->lockForUpdate()->firstOrFail();
            $settings = $tenant->settings ?? [];
            $before = $settings[$key] ?? [];
            $settings[$key] = $value;
            $tenant->update(['settings' => $settings]);
            TenantContext::set($tenant);
            Access::audit($tenant, $actor, 'settings.'.$key, $before, $value);
        });
    }

    private function validateHours(mixed $hours): void
    {
        if ($hours !== null && $hours !== '' && (! is_numeric($hours) || $hours <= 0 || $hours > 87600)) {
            throw new InvalidArgumentException('SLA targets must be greater than zero and at most 87600 hours.');
        }
    }
}
