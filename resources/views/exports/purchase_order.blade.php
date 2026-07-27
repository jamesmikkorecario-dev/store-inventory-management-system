<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $order->po_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.4;
        }
        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #4f46e5;
            padding-bottom: 10px;
        }
        .header h1 {
            font-size: 20px;
            margin: 0;
            color: #111827;
        }
        .header .meta {
            margin-top: 4px;
            color: #6b7280;
            font-size: 10px;
        }
        .status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            background: #eef2ff;
            color: #3730a3;
        }
        .panels {
            width: 100%;
            margin-bottom: 16px;
        }
        .panels td {
            vertical-align: top;
            width: 50%;
            padding: 0 8px 0 0;
        }
        .panel {
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px;
        }
        .panel h2 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            margin: 0 0 6px 0;
        }
        .items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        .items th {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            padding: 6px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #6b7280;
        }
        .items td {
            border-bottom: 1px solid #f3f4f6;
            padding: 6px;
        }
        .items tr:nth-child(even) td {
            background: #fcfcfd;
        }
        .num {
            text-align: right;
        }
        .totals td {
            border-top: 2px solid #d1d5db;
            font-weight: bold;
            padding: 8px 6px;
            background: #f9fafb;
        }
        .notes {
            margin-top: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 10px;
        }
        .footer {
            margin-top: 24px;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            font-size: 9px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Purchase Order {{ $order->po_number }}</h1>
        <div class="meta">
            <span class="status">{{ strtoupper($order->statusLabel()) }}</span>
            &nbsp;·&nbsp; Ordered {{ $order->order_date->format('Y-m-d') }}
            &nbsp;·&nbsp; Expected {{ $order->expected_delivery_date?->format('Y-m-d') ?? 'Not set' }}
        </div>
    </div>

    <table class="panels">
        <tr>
            <td>
                <div class="panel">
                    <h2>Supplier</h2>
                    <strong>{{ $order->supplier->name ?? 'Unassigned' }}</strong><br>
                    {{ $order->supplier->contact_person ?: '—' }}<br>
                    {{ $order->supplier->email ?: '—' }}<br>
                    {{ $order->supplier->phone ?: '—' }}<br>
                    {{ $order->supplier->address ?: '' }}
                </div>
            </td>
            <td>
                <div class="panel">
                    <h2>Order Details</h2>
                    Raised by: {{ $order->creator->name ?? 'Unknown' }}<br>
                    Raised on: {{ $order->created_at?->format('Y-m-d H:i') ?? '—' }}<br>
                    Approved by: {{ $order->approver->name ?? 'Not approved' }}<br>
                    Last receipt: {{ $order->received_at?->format('Y-m-d H:i') ?? 'None' }}<br>
                    Line items: {{ $order->items->count() }}
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Product</th>
                <th class="num">Ordered</th>
                <th class="num">Received</th>
                <th class="num">Outstanding</th>
                <th class="num">Unit Cost</th>
                <th class="num">Line Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($order->items as $item)
                <tr>
                    <td>{{ $item->product->sku ?? '—' }}</td>
                    <td>{{ $item->product->name ?? 'Deleted product' }}</td>
                    <td class="num">{{ number_format($item->quantity_ordered) }}</td>
                    <td class="num">{{ number_format($item->quantity_received) }}</td>
                    <td class="num">{{ number_format($item->outstandingQuantity()) }}</td>
                    <td class="num">${{ number_format((float) $item->unit_cost, 2) }}</td>
                    <td class="num">${{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="padding: 16px; text-align: center; color: #9ca3af;">This purchase order has no line items.</td>
                </tr>
            @endforelse
        </tbody>
        @if($order->items->isNotEmpty())
            <tfoot>
                <tr class="totals">
                    <td colspan="2">GRAND TOTAL</td>
                    <td class="num">{{ number_format($order->totalOrdered()) }}</td>
                    <td class="num">{{ number_format($order->totalReceived()) }}</td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num">${{ number_format((float) $order->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    @if($order->notes)
        <div class="notes">
            <strong>Notes</strong><br>
            {{ $order->notes }}
        </div>
    @endif

    <div class="footer">
        Generated {{ now()->format('Y-m-d H:i:s') }} · Store Inventory Management System
    </div>
</body>
</html>
