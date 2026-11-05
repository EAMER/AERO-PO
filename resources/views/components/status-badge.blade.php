@props(['status'])
@php
    $value = $status instanceof \App\Enums\PurchaseOrderStatus ? $status->value : $status;
    $color = match (true) {
        in_array($value, ['closed', 'fulfilled_from_stock', 'grn_issued']) => 'status-success',
        in_array($value, ['rejected', 'cancelled']) => 'status-danger',
        in_array($value, ['pending_approval', 'pending_cfo', 'awaiting_payment', 'returned_by_evaluation', 'returned_by_cfo']) => 'status-warning',
        default => 'status-info',
    };
    $label = match($value) {
        'pending_cfo' => 'Pending CFO', 'po_approved' => 'PO approved', 'po_sent' => 'PO sent',
        'rfq_sent' => 'RFQs sent', 'grn_issued' => 'GRN issued',
        'returned_by_evaluation' => 'Evaluation returned', 'returned_by_cfo' => 'Returned by CFO',
        default => ucwords(str_replace('_', ' ', $value)),
    };
@endphp
<span {{ $attributes->merge(['class' => "status-badge $color"]) }}>{{ $label }}</span>
