<?php

namespace App\Support;

use App\Enums\DocumentType as D;

/**
 * Which documents must exist, and how many, before a PO can move past a
 * given checklist stage. Only 'evaluation_pack' (manual 7.1.2) is wired up
 * this sprint; the others are placeholders for Sprint 2/3.
 *
 * @see \App\Services\DocumentChecklistService
 */
class RequiredDocuments
{
    /** @return array<string, int> DocumentType->value => minimum count */
    public static function forStage(string $stage): array
    {
        return match ($stage) {
            'evaluation_pack' => [
                D::EndUserDocument->value => 1,
                D::EvaluationSheet->value => 1,
                D::Quotation->value => 3,           // manual 7.1.2: at least 3 best quotes
                D::ProvisioningRequisition->value => 1,
                D::NegotiationEmail->value => 1,
                // Certificate: "if available" in the manual - not required.
            ],
            'acknowledgement' => [
                D::AcknowledgedPo->value => 1,
            ],
            'payment_cia' => [
                D::ProformaInvoice->value => 1,
                D::TelexCopy->value => 1,
            ],
            // 7.1.9-7.1.10: under 50kg, non-DG -> courier (DHL/FedEx).
            'shipping_light' => [
                D::CustomsDutyReceipt->value => 1,
                D::AirwayBill->value => 1,
            ],
            // 7.1.11-7.1.12: 50kg+ or dangerous goods -> freight forwarder.
            'shipping_heavy' => [
                D::CommercialInvoice->value => 1,
                D::AirwayBill->value => 1,
            ],
            default => [],
        };
    }
}
