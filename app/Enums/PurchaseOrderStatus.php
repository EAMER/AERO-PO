<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case RequisitionRaised = 'requisition_raised';
    case StockChecked = 'stock_checked';
    case FulfilledFromStock = 'fulfilled_from_stock';
    case RfqSent = 'rfq_sent';
    case QuotesIn = 'quotes_in';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case PendingCfo = 'pending_cfo';
    case PoSent = 'po_sent';
    case Acknowledged = 'acknowledged';
    case AwaitingPayment = 'awaiting_payment';
    case ReadyToShip = 'ready_to_ship';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Received = 'received';
    case GrnIssued = 'grn_issued';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::RequisitionRaised => [self::StockChecked, self::Cancelled],
            self::StockChecked => [self::FulfilledFromStock, self::RfqSent, self::Cancelled],
            self::FulfilledFromStock => [self::Closed],
            self::RfqSent => [self::QuotesIn, self::Cancelled],
            self::QuotesIn => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected],
            self::Approved => [self::PendingCfo],
            self::PendingCfo => [self::PoSent, self::Rejected],
            self::PoSent => [self::Acknowledged],
            // net terms skip payment; cash-in-advance waits for payment
            self::Acknowledged => [self::AwaitingPayment, self::ReadyToShip],
            self::AwaitingPayment => [self::ReadyToShip],
            self::ReadyToShip => [self::InTransit],
            self::InTransit => [self::Delivered],
            self::Delivered => [self::Received],
            self::Received => [self::GrnIssued],
            self::GrnIssued => [self::Closed],
            self::Closed, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isTerminal(): bool
    {
        return $this->next() === [];
    }

    /** Roles allowed to move a PO INTO this status (admins always may). @return list<Role> */
    public function enteredBy(): array
    {
        return match ($this) {
            self::RequisitionRaised => [Role::Requester, Role::LogisticsOfficer],
            self::StockChecked, self::FulfilledFromStock,
            self::Received => [Role::StoreSupervisor, Role::StoreStaff],
            self::GrnIssued => [Role::StoreSupervisor, Role::StoreStaff, Role::LogisticsOfficer],
            self::RfqSent, self::QuotesIn, self::PendingApproval, self::Acknowledged,
            self::AwaitingPayment, self::ReadyToShip, self::InTransit,
            self::Delivered, self::Closed => [Role::LogisticsOfficer],
            self::Approved, self::PendingCfo => [Role::Tlm, Role::Hamo, Role::TechnicalDirector],
            self::PoSent => [Role::Cfo, Role::LogisticsOfficer],
            self::Rejected => [Role::Tlm, Role::Hamo, Role::TechnicalDirector, Role::Cfo],
            self::Cancelled => [Role::Requester, Role::LogisticsOfficer],
        };
    }
}
