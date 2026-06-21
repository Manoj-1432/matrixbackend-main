<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <title>Payment Confirmed — Matrix Mobile Tyres</title>
  <style nonce="{{ $nonce }}">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh;
      min-height: 100dvh;
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
      background: #f5f5f5;
      color: #111827;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: clamp(1.25rem, 4vw, 2.5rem);
    }
    .shell {
      width: min(100%, 26rem);
      display: flex;
      flex-direction: column;
      align-items: stretch;
      gap: 1.25rem;
    }
    .card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 1.35rem;
      padding: clamp(2rem, 6vw, 2.5rem) clamp(1.5rem, 5vw, 2rem);
      box-shadow: 0 4px 24px rgba(0,0,0,0.07);
    }
    .icon-wrap {
      width: 3.5rem;
      height: 3.5rem;
      margin: 0 auto 1.5rem;
      border-radius: 50%;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      display: grid;
      place-items: center;
    }
    .icon-wrap svg {
      width: 1.5rem;
      height: 1.5rem;
      stroke: #16a34a;
      stroke-width: 2.5;
      fill: none;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .eyebrow {
      text-align: center;
      font-size: 0.68rem;
      font-weight: 600;
      letter-spacing: 0.3em;
      text-transform: uppercase;
      color: #9ca3af;
      margin-bottom: 0.4rem;
    }
    h1 {
      text-align: center;
      font-size: clamp(1.5rem, 4.5vw, 1.75rem);
      font-weight: 700;
      letter-spacing: -0.03em;
      color: #111827;
      margin-bottom: 0.5rem;
    }
    .sub {
      text-align: center;
      font-size: 0.82rem;
      color: #6b7280;
      line-height: 1.55;
      margin-bottom: 1.75rem;
    }
    .summary {
      border: 1px solid #e5e7eb;
      border-radius: 0.85rem;
      overflow: hidden;
      margin-bottom: 1.75rem;
    }
    .row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.7rem 1rem;
      font-size: 0.875rem;
      gap: 1rem;
    }
    .row:nth-child(even) { background: #f9fafb; }
    .row-label { color: #6b7280; white-space: nowrap; }
    .row-value { font-weight: 500; text-align: right; word-break: break-word; color: #111827; }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.25rem 0.65rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 600;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #15803d;
    }
    .badge-dot {
      width: 0.4rem;
      height: 0.4rem;
      border-radius: 50%;
      background: #16a34a;
    }
    .btn {
      display: block;
      width: 100%;
      padding: 0.85rem 1.25rem;
      border-radius: 0.85rem;
      background: #111827;
      color: #ffffff;
      font-size: 0.92rem;
      font-weight: 700;
      text-align: center;
      text-decoration: none;
      letter-spacing: 0.01em;
      transition: opacity 0.18s ease, transform 0.15s ease;
    }
    .btn:hover  { opacity: 0.88; }
    .btn:active { transform: scale(0.98); }
    .btn:focus-visible { outline: 2px solid #374151; outline-offset: 3px; }
    .credit {
      text-align: center;
      font-size: 0.7rem;
      color: #9ca3af;
      letter-spacing: 0.04em;
    }
  </style>
</head>
<body>
  <main class="shell">
    <div class="card">
      <div class="icon-wrap" aria-hidden="true">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>

      <p class="eyebrow">Matrix Mobile Tyres</p>
      <h1>Payment confirmed</h1>
      <p class="sub">Your booking is locked in. A confirmation email is on its way to you.</p>

      <div class="summary" role="table" aria-label="Order summary">
        <div class="row">
          <span class="row-label">Order</span>
          <span class="row-value">#{{ $order->id }}</span>
        </div>
        <div class="row">
          <span class="row-label">Service</span>
          <span class="row-value">{{ $order->service_type }}</span>
        </div>
        <div class="row">
          <span class="row-label">Tyre</span>
          <span class="row-value">{{ $order->tyre_brand }} {{ $order->tyre_model }}<br><small style="color:#9ca3af;font-weight:400">{{ $order->tyre_size }} &times; {{ $order->tyre_quantity }}</small></span>
        </div>
        @if(!empty($order->vehicle_registration))
        <div class="row">
          <span class="row-label">Vehicle</span>
          <span class="row-value">{{ $order->vehicle_registration }}</span>
        </div>
        @endif
        <div class="row">
          <span class="row-label">Fitting date</span>
          <span class="row-value">{{ $order->fitting_date?->format('d M Y') ?? '—' }}</span>
        </div>
        <div class="row">
          <span class="row-label">Total paid</span>
          <span class="row-value" style="font-size:1rem;font-weight:700">{{ $currencySymbol }}{{ number_format((float) $order->amount, 2) }}</span>
        </div>
        <div class="row">
          <span class="row-label">Status</span>
          <span class="row-value">
            <span class="badge"><span class="badge-dot"></span>{{ ucfirst((string) $order->payment_status) }}</span>
          </span>
        </div>
      </div>

      <a href="{{ $homeUrl }}" class="btn">Return to home</a>
    </div>

    <p class="credit">Matrix Mobile Tyres</p>
  </main>
</body>
</html>
