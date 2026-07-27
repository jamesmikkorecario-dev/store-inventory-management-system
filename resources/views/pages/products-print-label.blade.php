<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Label - {{ $product->identifier }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: #fff;
            color: #000;
        }
        .label-container {
            border: 2px dashed #ccc;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            max-width: 400px;
            width: 100%;
            margin-bottom: 20px;
        }
        .product-name {
            font-size: 1.25rem;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .product-sku {
            font-size: 0.875rem;
            color: #555;
            margin-bottom: 16px;
        }
        .barcode-wrapper {
            margin-bottom: 16px;
        }
        .barcode-svg {
            height: 60px;
            width: 100%;
            display: flex;
            justify-content: center;
        }
        .qr-svg {
            height: 120px;
            width: 120px;
            margin: 0 auto;
        }
        .identifier-text {
            font-family: monospace;
            font-size: 1rem;
            letter-spacing: 2px;
            margin-top: 8px;
        }
        @media print {
            body {
                padding: 0;
            }
            .label-container {
                border: none;
                margin: 0;
                padding: 0;
                page-break-inside: avoid;
            }
            .no-print {
                display: none;
            }
        }
        .print-btn {
            padding: 10px 20px;
            background-color: #000;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            cursor: pointer;
            margin-bottom: 30px;
        }
        .print-btn:hover {
            background-color: #333;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">Print Label</button>

    <div class="label-container">
        <div class="product-name">{{ $product->name }}</div>
        <div class="product-sku">SKU: {{ $product->sku }}</div>
        
        @if(in_array($type, ['barcode', 'both']))
            <div class="barcode-wrapper">
                <div class="barcode-svg">
                    {!! $barcodeSvg !!}
                </div>
                <div class="identifier-text">{{ $product->identifier }}</div>
            </div>
        @endif

        @if(in_array($type, ['qr', 'both']))
            <div class="qr-wrapper" style="margin-top: {{ $type === 'both' ? '24px' : '0' }}">
                <div class="qr-svg">
                    {!! $qrCodeSvg !!}
                </div>
                @if($type === 'qr')
                    <div class="identifier-text">{{ $product->identifier }}</div>
                @endif
            </div>
        @endif
    </div>

    <script>
        // Auto-trigger print dialog after a short delay to allow SVGs to render
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
