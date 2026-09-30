<?php

namespace App\Enums;

enum DocumentType: string
{
    // Evaluation / approval pack (7.1.2)
    case EndUserDocument = 'end_user_document';
    case EvaluationSheet = 'evaluation_sheet';
    case Quotation = 'quotation';
    case ProvisioningRequisition = 'provisioning_requisition';
    case NegotiationEmail = 'negotiation_email';
    case Certificate = 'certificate';

    // PO issue / acknowledgement (7.1.3, 7.1.4)
    case PurchaseOrderDocument = 'purchase_order_document';
    case AcknowledgedPo = 'acknowledged_po';

    // Payment - cash in advance (7.1.5-7.1.8)
    case ProformaInvoice = 'proforma_invoice';
    case TelexCopy = 'telex_copy';

    // Shipping (7.1.9-7.1.12)
    case ShopReport = 'shop_report';
    case CommercialInvoice = 'commercial_invoice';
    case CustomsDutyReceipt = 'customs_duty_receipt';
    case AirwayBill = 'airway_bill';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
