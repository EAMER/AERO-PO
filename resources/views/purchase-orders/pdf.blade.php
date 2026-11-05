<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Purchase order {{ $snapshot['reference'] }}</title>
<style>body{font-family:Helvetica,sans-serif;font-size:11px;color:#172033}h1{font-size:22px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:8px;border:1px solid #bbb;text-align:left}th{background:#eee}.total{text-align:right;font-size:15px;margin-top:20px}</style></head>
<body>
<h1>{{ $snapshot['company'] }}</h1><h2>Purchase order {{ $snapshot['reference'] }}</h2>
<p>Revision {{ $snapshot['revision'] }} | {{ $po->po_approved_at ? 'CFO approved '.$po->po_approved_at->format('d M Y H:i T') : 'Pending CFO approval' }}</p>
<p><strong>Vendor:</strong> {{ $snapshot['vendor']['name'] }}<br>{{ $snapshot['vendor']['address'] ?? '' }}<br>{{ $snapshot['vendor']['email'] }}</p>
<p><strong>Payment terms:</strong> {{ $snapshot['payment_terms'] === 'cia' ? 'Cash in advance' : 'Net terms; payment after Stores receipt' }}</p>
<table><thead><tr><th>Part</th><th>Description</th><th>Qty</th><th>Condition</th><th>Warranty</th><th>Unit price</th><th>Line total</th></tr></thead><tbody>
@foreach ($snapshot['lines'] as $line)
<tr><td>{{ $line['part_number'] }}</td><td>{{ $line['description'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['condition'] }}</td><td>{{ $line['warranty'] }}</td><td>{{ \App\Support\Money::format($line['unit_price_minor']) }}</td><td>{{ \App\Support\Money::format($line['unit_price_minor'] * $line['quantity']) }}</td></tr>
@endforeach
</tbody></table><p class="total">Total {{ $snapshot['currency'] }} {{ \App\Support\Money::format($snapshot['total_minor']) }}</p>
<p>Please acknowledge this purchase order using the reference above.</p>
</body></html>
