@props(['status'])
@php
    $value = $status instanceof \App\Enums\PurchaseOrderStatus ? $status->value : $status;
    $color = match (true) {
        in_array($value, ['closed', 'fulfilled_from_stock', 'grn_issued']) => 'bg-green-100 text-green-800',
        in_array($value, ['rejected', 'cancelled']) => 'bg-red-100 text-red-800',
        in_array($value, ['pending_approval', 'pending_cfo', 'awaiting_payment']) => 'bg-yellow-100 text-yellow-800',
        default => 'bg-blue-100 text-blue-800',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex px-2 py-1 text-xs font-semibold rounded-full $color"]) }}>
    {{ ucwords(str_replace('_', ' ', $value)) }}
</span>
