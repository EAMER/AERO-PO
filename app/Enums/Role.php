<?php

namespace App\Enums;

enum Role: string
{
    case Requester = 'requester';
    case LogisticsOfficer = 'logistics_officer';
    case Tlm = 'tlm';
    case Hamo = 'hamo';
    case TechnicalDirector = 'technical_director';
    case Cfo = 'cfo';
    case CostControl = 'cost_control';
    case InternalAudit = 'internal_audit';
    case Payables = 'payables';
    case Treasury = 'treasury';
    case StoreSupervisor = 'store_supervisor';
    case StoreStaff = 'store_staff';
    case TenantAdmin = 'tenant_admin';
    case SuperAdmin = 'super_admin';

    public function isAdmin(): bool
    {
        return in_array($this, [self::TenantAdmin, self::SuperAdmin], true);
    }
}
