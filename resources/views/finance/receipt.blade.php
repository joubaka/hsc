<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $receipt->receipt_number }}</title>
    <style>
        body { max-width: 760px; margin: 40px auto; color: #222; font: 16px/1.5 Arial, sans-serif; }
        header { border-bottom: 3px solid #245c3d; margin-bottom: 28px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
        .amount { font-size: 1.6rem; font-weight: bold; }
        @media print { button { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
<header><h1>Hermanus Sports Club</h1><h2>Payment receipt</h2></header>
<table>
    <tr><th>Receipt</th><td>{{ $receipt->receipt_number }}</td></tr>
    <tr><th>Payment reference</th><td>{{ $receipt->snapshot['payment_reference'] }}</td></tr>
    <tr><th>Payer</th><td>{{ $receipt->snapshot['payer_name'] }}</td></tr>
    <tr><th>Received</th><td>{{ $receipt->snapshot['received_on'] }}</td></tr>
    <tr><th>Method</th><td>{{ str_replace('_', ' ', $receipt->snapshot['payment_method']) }}</td></tr>
    <tr><th>Amount</th><td class="amount">{{ $receipt->snapshot['currency'] }} {{ number_format($receipt->snapshot['amount'] / 100, 2) }}</td></tr>
    <tr><th>Unallocated when issued</th><td>{{ $receipt->snapshot['currency'] }} {{ number_format($receipt->snapshot['unallocated_amount'] / 100, 2) }}</td></tr>
</table>
<p>This immutable receipt records the payment as received. Current allocations are available on the member statement.</p>
<button onclick="window.print()">Print</button>
</body>
</html>
