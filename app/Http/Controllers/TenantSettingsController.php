<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\KpiDefinition;
use App\Models\User;
use App\Services\TenantSettingsService;
use App\Support\ProcurementAccess;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantSettingsController extends Controller
{
    public function index(Request $request, TenantSettingsService $service): View
    {
        ProcurementAccess::roles($request->user(), Role::Tlm);
        $stages = ['evaluation', 'cfo', 'freight', 'payment_cia', 'payment_net'];

        return view('settings.index', ['tenant' => TenantContext::get(), 'users' => User::where('is_active', true)->orderBy('name')->get(), 'stages' => collect($stages)->mapWithKeys(fn ($stage) => [$stage => $service->rolesFor($stage, true)])->all(), 'statuses' => PurchaseOrderStatus::cases(), 'kpis' => KpiDefinition::all(), 'metrics' => TenantSettingsService::METRICS]);
    }

    public function approvers(Request $request, TenantSettingsService $service): RedirectResponse
    {
        $data = $request->validate(['approvers' => 'required|array', 'approvers.*' => 'array', 'approvers.*.*' => 'nullable|integer|min:1']);

        return $this->complete(fn () => $service->saveApprovers($request->user(), $data['approvers']));
    }

    public function slas(Request $request, TenantSettingsService $service): RedirectResponse
    {
        $data = $request->validate(['sla_hours' => 'required|array', 'approval_sla_hours' => 'required|array', 'sla_hours.*' => 'nullable|numeric|gt:0|max:87600', 'approval_sla_hours.*' => 'nullable|numeric|gt:0|max:87600']);

        return $this->complete(fn () => $service->saveSlas($request->user(), $data['sla_hours'], $data['approval_sla_hours']));
    }

    public function kpi(Request $request, TenantSettingsService $service): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'metric' => 'required|string', 'target' => 'required|numeric|min:0|max:9999999999', 'direction' => 'required|in:at_most,at_least']);

        return $this->complete(fn () => $service->defineKpi($request->user(), $data));
    }
}
