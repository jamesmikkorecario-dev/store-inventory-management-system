<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
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
        .header p {
            margin: 5px 0 0 0;
            color: #4b5563;
            font-size: 12px;
        }
        .meta-table {
            width: 100%;
            margin-bottom: 20px;
            font-size: 10px;
        }
        .meta-table td {
            padding: 2px 0;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .report-table th {
            background-color: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
            color: #374151;
            font-weight: bold;
            text-align: left;
            padding: 8px;
            text-transform: uppercase;
            font-size: 9px;
        }
        .report-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 8px;
            vertical-align: middle;
        }
        .report-table tr:nth-child(even) td {
            background-color: #fafafa;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-semibold {
            font-weight: 600;
        }
        .badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-danger { background-color: #fee2e2; color: #991b1b; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-info { background-color: #e0f2fe; color: #075985; }
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
        }
        .totals-row td {
            background-color: #f3f4f6 !important;
            font-weight: bold;
            border-top: 2px solid #d1d5db;
            border-bottom: 2px solid #d1d5db;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <p>Store Inventory Management System (SIMS) Report Output</p>
    </div>

    <table class="meta-table">
        <tr>
            <td width="15%" class="font-semibold">Generated At:</td>
            <td width="35%">{{ now()->format('Y-m-d H:i:s') }}</td>
            <td width="15%" class="font-semibold">Operator:</td>
            <td width="35%">{{ $operator }}</td>
        </tr>
        <tr>
            <td class="font-semibold">Date Range:</td>
            <td>{{ $dateRange }}</td>
            <td class="font-semibold">Filters Applied:</td>
            <td>{{ $filters }}</td>
        </tr>
    </table>

    <table class="report-table">
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th class="{{ $header['class'] ?? '' }}">{{ $header['title'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td class="{{ $cell['class'] ?? '' }}">
                            @if(isset($cell['badge']))
                                <span class="badge badge-{{ $cell['badge'] }}">{{ $cell['value'] }}</span>
                            @else
                                {!! $cell['value'] !!}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" class="text-center" style="padding: 20px;">No report data matches current filters.</td>
                </tr>
            @endforelse

            @if(isset($totals) && count($rows) > 0)
                <tr class="totals-row">
                    @foreach($totals as $total)
                        <td class="{{ $total['class'] ?? '' }}">{!! $total['value'] !!}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    @if(! empty($note))
        <p style="font-size: 9px; color: #92400e; background-color: #fef3c7; padding: 6px 8px; border-radius: 4px;">
            {{ $note }}
        </p>
    @endif

    <div class="footer">
        Confidential Report - System Generated on behalf of SIMS Administration. Page 1 of 1.
    </div></body>
</html>
