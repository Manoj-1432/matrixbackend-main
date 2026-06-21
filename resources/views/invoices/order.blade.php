@php
    /** @var \App\Models\Order $order */
    /** @var \App\Models\User|null $customer */
    /** @var array<int,array{description:string,qty:int|float,unit_price:float,subtotal:float}> $lines */
    /** @var float $subtotal */
    /** @var float $total */
    /** @var string $currencySymbol */
    /** @var string $brandName */
    /** @var string $companyAddress */
    /** @var string $companyPhone */
    /** @var string $companyEmail */
    /** @var string $vatNumber */
    /** @var string|null $logoPath */
    /** @var string $invoiceNumber */
    /** @var \Illuminate\Support\Carbon $invoiceDate */

    $fmt = static fn (float $v) => $currencySymbol . number_format($v, 2);

    $customerLines = [];
    if ($customer) {
        if (!empty($customer->name)) {
            $customerLines[] = $customer->name;
        }
        $addressParts = array_filter([
            $customer->address ?? null,
            $customer->city ?? null,
            $customer->postcode ?? null,
        ]);
        if (!empty($addressParts)) {
            $customerLines[] = implode(', ', $addressParts);
        }
        if (!empty($customer->email)) {
            $customerLines[] = $customer->email;
        }
        if (!empty($customer->phone)) {
            $customerLines[] = 'Phone: ' . $customer->phone;
        }
    }
    if (empty($customerLines)) {
        $customerLines[] = 'Walk-in customer';
    }

    $companyAddressParts = array_values(array_filter(array_map('trim', explode(',', $companyAddress))));
    $serviceSummary = trim(implode(' ', array_filter([
        $order->service_type ?? null,
        $order->vehicle_registration ? '- ' . $order->vehicle_registration : null,
    ])));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoiceNumber }}</title>
    <style>
        @page { margin: 32px 36px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111827;
            font-size: 11pt;
            margin: 0;
            padding: 0;
        }
        .header {
            padding-bottom: 14px;
        }
        .logo-row {
            margin-bottom: 8px;
            width: 100%;
            border-collapse: collapse;
        }
        .logo-row td {
            vertical-align: top;
        }
        .logo-row img {
            max-width: 170px;
            max-height: 70px;
        }
        .logo-right {
            text-align: right;
            color: #4b5563;
            font-size: 10pt;
        }
        .logo-right .label {
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
        }
        .brand-text {
            font-size: 18pt;
            font-weight: bold;
            color: #1d3a8a;
            letter-spacing: 1px;
        }
        .brand-sub {
            font-size: 9pt;
            color: #1d3a8a;
            letter-spacing: 4px;
            text-transform: uppercase;
            margin-top: 2px;
        }
        hr.rule {
            border: 0;
            border-top: 1px solid #cbd5e1;
            margin: 0;
        }
        .invoice-box {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
            margin: 10px 0 14px 0;
            background: #fafafa;
        }
        .invoice-box table {
            width: 100%;
            border-collapse: collapse;
        }
        .invoice-box td {
            padding: 2px 0;
            vertical-align: top;
        }
        .invoice-box .k {
            font-size: 9pt;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .invoice-box .v {
            font-size: 11pt;
            color: #111827;
            font-weight: bold;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            margin-bottom: 8px;
        }
        .meta td {
            vertical-align: top;
            padding: 8px 0;
        }
        .meta .right {
            text-align: right;
        }
        .meta .label {
            font-weight: bold;
            color: #111827;
        }
        .billto {
            font-size: 11pt;
            width: 58%;
        }
        .billto .lines div {
            margin-top: 2px;
            color: #374151;
        }
        .service {
            width: 42%;
            padding-left: 26px;
            font-size: 10pt;
            color: #4b5563;
        }
        .service .value {
            margin-top: 2px;
            color: #111827;
            font-weight: bold;
            line-height: 1.4;
        }
        .body-section {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .body-section td {
            vertical-align: top;
        }
        .notice-bottom {
            margin-top: 14px;
            font-weight: bold;
            font-size: 10pt;
            color: #111827;
            line-height: 1.4;
            max-width: 360px;
        }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5pt;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
        }
        table.lines th {
            text-align: left;
            font-weight: bold;
            color: #111827;
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        table.lines th.num {
            text-align: right;
        }
        table.lines td {
            padding: 9px 8px;
            color: #1f2937;
            border-bottom: 1px solid #f1f5f9;
        }
        table.lines td.desc {
            color: #4b5563;
        }
        table.lines td.num {
            text-align: right;
            white-space: nowrap;
        }
        table.lines tr:last-child td {
            border-bottom: 0;
        }
        .totals {
            margin-top: 14px;
            margin-left: auto;
            width: 270px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 11pt;
            background: #fafafa;
        }
        .totals .row {
            margin-top: 2px;
            text-align: right;
        }
        .totals .grand {
            font-weight: bold;
            font-size: 12pt;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid #e5e7eb;
            text-align: right;
        }
        .footer {
            margin-top: 28px;
            padding-top: 14px;
            border-top: 1px solid #cbd5e1;
            font-size: 10pt;
            color: #374151;
        }
        .footer table {
            width: 100%;
            border-collapse: collapse;
        }
        .footer td {
            vertical-align: top;
        }
        .footer .company-name {
            font-weight: bold;
            color: #111827;
            font-size: 11pt;
        }
        .footer .right {
            text-align: left;
        }
        .muted {
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="logo-row">
            <tr>
                <td>
                    @if ($logoPath)
                        <img src="{{ $logoPath }}" alt="{{ $brandName }}">
                    @else
                        <div class="brand-text">{{ $brandName }}</div>
                        <div class="brand-sub">Mobile Tyres</div>
                    @endif
                </td>
                <td class="logo-right">
                    <div class="label">Issued By</div>
                    <div><strong>{{ $brandName }}</strong></div>
                    @foreach ($companyAddressParts as $part)
                        <div>{{ $part }}</div>
                    @endforeach
                    @if ($companyPhone !== '')
                        <div>{{ $companyPhone }}</div>
                    @endif
                    @if ($companyEmail !== '')
                        <div>{{ $companyEmail }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <hr class="rule">

    <div class="invoice-box">
        <table>
            <tr>
                <td>
                    <div class="k">Invoice Number</div>
                    <div class="v">{{ $invoiceNumber }}</div>
                </td>
                <td>
                    <div class="k">Invoice Date</div>
                    <div class="v">{{ $invoiceDate->format('d M Y') }}</div>
                </td>
                <td>
                    <div class="k">Payment</div>
                    <div class="v">{{ !empty($order->payment_status) ? ucfirst((string) $order->payment_status) : 'N/A' }}</div>
                </td>
                <td>
                    <div class="k">Order Status</div>
                    <div class="v">{{ ucfirst((string) $order->status) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td class="billto">
                <div class="label">Bill To:</div>
                <div class="lines">
                    @foreach ($customerLines as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                </div>
            </td>
            <td class="service">
                <div class="label">Service Details:</div>
                <div class="value">{{ $serviceSummary !== '' ? $serviceSummary : 'Tyre fitting service' }}</div>
                @if ($order->fitting_date)
                    <div class="muted">Fitting Date: {{ $order->fitting_date->format('d M Y') }}</div>
                @endif
                @if ($order->slot)
                    <div class="muted">Slot: {{ ucfirst((string) $order->slot->day) }} {{ $order->slot->start_time }} - {{ $order->slot->end_time }}</div>
                @endif
            </td>
        </tr>
    </table>

    <hr class="rule">

    <table class="body-section">
        <tr>
            <td>
                <table class="lines">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="num">Qty</th>
                            <th class="num">Unit Price</th>
                            <th class="num">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $line)
                            <tr>
                                <td class="desc">{{ $line['description'] }}</td>
                                <td class="num">{{ $line['qty'] }}</td>
                                <td class="num">{{ $fmt((float) $line['unit_price']) }}</td>
                                <td class="num">{{ $fmt((float) $line['subtotal']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <div class="totals">
        <div class="row">Subtotal: {{ $fmt($subtotal) }}</div>
        <div class="grand">Total: {{ $fmt($total) }}</div>
    </div>

    <div class="notice-bottom">
        Thank you for choosing {{ $brandName }}.<br>
        No warranty on puncture repairs.<br>
        Please retain this invoice for your<br>
        records.
    </div>

    <div class="footer">
        <table>
            <tr>
                <td>
                    <div class="company-name">{{ $brandName }}</div>
                    @if ($vatNumber !== '')
                        <div>VAT No: {{ $vatNumber }}</div>
                    @endif
                </td>
                <td class="right">
                    @foreach ($companyAddressParts as $part)
                        <div>{{ $part }}</div>
                    @endforeach
                    @if ($companyPhone !== '')
                        <div>Phone: {{ $companyPhone }}</div>
                    @endif
                    @if ($companyEmail !== '')
                        <div>Email: {{ $companyEmail }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
