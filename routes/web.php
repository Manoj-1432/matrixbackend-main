<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Local dev landing (API-first app)
|--------------------------------------------------------------------------
|
| If you open the backend in a browser, use http://127.0.0.1:8000 — not https://.
| https:// on this port makes Chrome show “invalid response” because there is no TLS.
*/

Route::get('/', function () {
    $nonce = base64_encode(random_bytes(16));
    $nonceAttr = e($nonce);
    $instagramUrl = e((string) config('workatmo.instagram_url'));
    $phoneDigits = preg_replace('/\D+/', '', (string) config('workatmo.whatsapp_phone'));
    $waBody = trim((string) config('workatmo.whatsapp_message'));
    $whatsappUrl = e('https://wa.me/'.$phoneDigits.'?text='.rawurlencode($waBody));

    $csp = implode('; ', [
        "default-src 'none'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-ancestors 'none'",
        "img-src 'none'",
        "font-src 'none'",
        "object-src 'none'",
        "connect-src 'none'",
        "script-src 'none'",
        "style-src 'nonce-{$nonce}'",
    ]);

    return response(
        <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <meta name="color-scheme" content="dark">
  <title>Matrix</title>
  <style nonce="{$nonceAttr}">
    *, *::before, *::after { box-sizing: border-box; }
    :root {
      color-scheme: dark;
      --bg0: #06060a;
      --bg1: #0c0c14;
      --accent: #3ee8a8;
      --accent-dim: color-mix(in srgb, var(--accent) 35%, transparent);
      --text: #e8eaef;
      --text-soft: #8b919d;
      --card: color-mix(in srgb, #12121c 78%, transparent);
      --stroke: color-mix(in srgb, var(--accent) 22%, #2a2a38);
    }
    body {
      margin: 0;
      min-height: 100vh;
      min-height: 100dvh;
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
      background: var(--bg0);
      color: var(--text);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: clamp(1.25rem, 4vw, 2.5rem);
      overflow-x: hidden;
    }
    body:has(#wm-toggle:checked) {
      overflow: hidden;
    }
    .wm-sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
    .shell {
      position: relative;
      width: min(100%, 22rem);
      display: flex;
      flex-direction: column;
      align-items: stretch;
    }
    .shell::before {
      content: "";
      position: fixed;
      inset: -40%;
      background:
        radial-gradient(ellipse 55% 45% at 50% 0%, var(--accent-dim), transparent 55%),
        radial-gradient(ellipse 40% 35% at 85% 75%, color-mix(in srgb, #5b8cff 25%, transparent), transparent 50%),
        radial-gradient(ellipse 35% 30% at 10% 80%, color-mix(in srgb, var(--accent) 12%, transparent), transparent 45%);
      pointer-events: none;
      z-index: 0;
    }
    .card {
      position: relative;
      z-index: 1;
      width: 100%;
      text-align: center;
      padding: clamp(2.25rem, 6vw, 3rem) clamp(1.75rem, 5vw, 2.5rem);
      border-radius: 1.35rem;
      background: var(--card);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid var(--stroke);
      box-shadow:
        0 0 0 1px color-mix(in srgb, #fff 4%, transparent) inset,
        0 24px 48px -12px rgba(0, 0, 0, 0.55);
    }
    .mark {
      width: 3rem;
      height: 3rem;
      margin: 0 auto 1.35rem;
      border-radius: 0.85rem;
      background: linear-gradient(145deg, color-mix(in srgb, var(--accent) 55%, #1a1a24), #14141c);
      border: 1px solid color-mix(in srgb, var(--accent) 35%, transparent);
      box-shadow: 0 8px 24px -8px color-mix(in srgb, var(--accent) 45%, transparent);
      display: grid;
      place-items: center;
    }
    .mark svg {
      width: 1.35rem;
      height: 1.35rem;
      stroke: var(--accent);
      stroke-width: 2;
      fill: none;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .label {
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.28em;
      text-transform: uppercase;
      color: var(--text-soft);
      margin-bottom: 0.35rem;
    }
    h1 {
      margin: 0 0 1.75rem;
      font-size: clamp(1.65rem, 5vw, 1.95rem);
      font-weight: 600;
      letter-spacing: -0.03em;
      line-height: 1.2;
    }
    .status {
      display: inline-flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.5rem 1rem 0.5rem 0.65rem;
      border-radius: 999px;
      background: color-mix(in srgb, var(--accent) 8%, #0e0e14);
      border: 1px solid color-mix(in srgb, var(--accent) 18%, transparent);
      font-size: 0.9rem;
      font-weight: 500;
      color: color-mix(in srgb, var(--accent) 92%, var(--text));
    }
    .dot {
      width: 0.5rem;
      height: 0.5rem;
      border-radius: 50%;
      background: var(--accent);
      box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 55%, transparent);
      animation: pulse 2.4s ease-out infinite;
    }
    @keyframes pulse {
      0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 45%, transparent); opacity: 1; }
      50% { box-shadow: 0 0 0 10px transparent; opacity: 0.95; }
    }
    .sub {
      margin-top: 1.35rem;
      font-size: 0.8rem;
      color: var(--text-soft);
      letter-spacing: 0.02em;
    }
    .credit {
      position: relative;
      z-index: 1;
      width: 100%;
      margin: 1.85rem 0 0;
      font-size: 0.72rem;
      color: var(--text-soft);
      line-height: 1.55;
      letter-spacing: 0.04em;
      text-align: center;
    }
    .credit-link {
      color: color-mix(in srgb, var(--accent) 72%, var(--text));
      text-decoration: none;
      font-weight: 500;
      letter-spacing: 0.02em;
      border-bottom: 1px solid color-mix(in srgb, var(--accent) 32%, transparent);
      transition: color 0.2s ease, border-color 0.2s ease;
    }
    .credit-link:hover {
      color: var(--accent);
      border-bottom-color: color-mix(in srgb, var(--accent) 55%, transparent);
    }
    .credit-link:focus-visible {
      outline: 2px solid color-mix(in srgb, var(--accent) 65%, transparent);
      outline-offset: 3px;
      border-radius: 2px;
    }
    label.credit-link {
      cursor: pointer;
      display: inline;
    }
    .wm-pop {
      position: fixed;
      inset: 0;
      z-index: 100;
      display: grid;
      place-items: center;
      padding: clamp(1rem, 4vw, 2rem);
      pointer-events: none;
      visibility: hidden;
      opacity: 0;
      transition: opacity 0.22s ease, visibility 0.22s ease;
    }
    #wm-toggle:checked ~ .wm-pop {
      pointer-events: auto;
      visibility: visible;
      opacity: 1;
    }
    .wm-pop-bg {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.72);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      cursor: pointer;
    }
    .wm-pop-card {
      position: relative;
      z-index: 1;
      width: min(100%, 19rem);
      padding: 1.35rem 1.25rem 1.25rem;
      border-radius: 1.15rem;
      background: color-mix(in srgb, #14141f 92%, transparent);
      border: 1px solid var(--stroke);
      box-shadow: 0 28px 56px -16px rgba(0, 0, 0, 0.65);
      text-align: center;
    }
    .wm-pop-title {
      margin: 0 0 0.35rem;
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: -0.02em;
    }
    .wm-pop-sub {
      margin: 0 0 1.15rem;
      font-size: 0.78rem;
      color: var(--text-soft);
      line-height: 1.45;
    }
    .wm-pop-actions {
      display: flex;
      flex-direction: column;
      gap: 0.55rem;
    }
    .wm-action {
      display: block;
      padding: 0.72rem 1rem;
      border-radius: 0.75rem;
      font-size: 0.88rem;
      font-weight: 600;
      text-decoration: none;
      text-align: center;
      transition: transform 0.15s ease, filter 0.15s ease;
    }
    .wm-action:hover {
      filter: brightness(1.08);
    }
    .wm-action:active {
      transform: scale(0.98);
    }
    .wm-action:focus-visible {
      outline: 2px solid color-mix(in srgb, var(--accent) 65%, transparent);
      outline-offset: 2px;
    }
    .wm-wa {
      background: linear-gradient(160deg, #25d366, #128c7e);
      color: #fff;
      border: 1px solid color-mix(in srgb, #fff 18%, transparent);
    }
    .wm-ig {
      background: color-mix(in srgb, #e1306c 18%, #1a1a24);
      color: #f4f4f6;
      border: 1px solid color-mix(in srgb, #e1306c 35%, transparent);
    }
    .wm-pop-x {
      position: absolute;
      top: 0.55rem;
      right: 0.55rem;
      width: 2rem;
      height: 2rem;
      display: grid;
      place-items: center;
      border-radius: 0.4rem;
      font-size: 1.25rem;
      line-height: 1;
      color: var(--text-soft);
      cursor: pointer;
      border: none;
      background: transparent;
      transition: color 0.15s ease, background 0.15s ease;
    }
    .wm-pop-x:hover {
      color: var(--text);
      background: color-mix(in srgb, #fff 6%, transparent);
    }
    .wm-pop-x:focus-visible {
      outline: 2px solid color-mix(in srgb, var(--accent) 55%, transparent);
      outline-offset: 1px;
    }
  </style>
</head>
<body>
  <main class="shell">
    <input class="wm-sr-only" type="checkbox" id="wm-toggle" aria-hidden="true">
    <section class="card" aria-labelledby="title">
      <div class="mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M3 12h18M6 6l12 12M18 6L6 18"/></svg>
      </div>
      <p class="label">Matrix</p>
      <h1 id="title">Backend</h1>
      <div class="status" role="status">
        <span class="dot"></span>
        Running
      </div>
      <p class="sub">Ready to accept requests</p>
    </section>
    <p class="credit">
      Developed by
      <label class="credit-link" for="wm-toggle">Workatmo Technologies Pvt Ltd</label>
    </p>
    <div class="wm-pop">
      <label class="wm-pop-bg" for="wm-toggle"><span class="wm-sr-only">Close</span></label>
      <div class="wm-pop-card" role="dialog" aria-labelledby="wm-pop-title" aria-modal="true">
        <label class="wm-pop-x" for="wm-toggle" title="Close" aria-label="Close">×</label>
        <h2 class="wm-pop-title" id="wm-pop-title">Contact Workatmo</h2>
        <p class="wm-pop-sub">Message us on WhatsApp (with a short prefilled note), or open our Instagram profile.</p>
        <div class="wm-pop-actions">
          <a class="wm-action wm-wa" href="{$whatsappUrl}" target="_blank" rel="noopener noreferrer">WhatsApp</a>
          <a class="wm-action wm-ig" href="{$instagramUrl}" target="_blank" rel="noopener noreferrer">Instagram</a>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
HTML
        ,
        200,
        [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-DNS-Prefetch-Control' => 'off',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => $csp,
        ]
    );
});

Route::get('/booking', [\App\Http\Controllers\CheckoutController::class, 'bookingPage']);
Route::get('/checkout/success', [\App\Http\Controllers\CheckoutController::class, 'successPage']);

// Fallback route for serving storage files if the public symlink is missing (common on shared hosting)
Route::get('/storage/{path}', function (string $path) {
    $path = storage_path('app/public/' . $path);
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->file($path);
})->where('path', '.*');
