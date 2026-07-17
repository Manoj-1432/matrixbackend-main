<?php

use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PublicBrandController;
use App\Http\Controllers\PublicTyreController;
use App\Http\Controllers\PublicSettingsController;
use App\Http\Controllers\PublicTyreSearchOptionsController;
use App\Http\Controllers\PublicTyreTypeController;
use App\Http\Controllers\VehicleLookupController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);

// Temporary debug: show SMTP config and send a test email
Route::get('/debug/smtp', function () {
    $mailer = app(\App\Services\AdminSmtpMailer::class);
    $config = $mailer->resolveSmtpConfig();
    if ($config === null) {
        $settings = \App\Models\Setting::query()
            ->whereIn('key', ['smtp_enabled','smtp_host','smtp_port','smtp_from_email','smtp_username','smtp_encryption'])
            ->pluck('value', 'key');
        return response()->json(['error' => 'SMTP not configured or disabled', 'settings' => $settings]);
    }
    // SMTP config is valid — use /api/debug/resend-email/{orderId} to send a real test
    return response()->json([
        'smtp_config' => $config['mailer'],
        'from_email'  => $config['from_email'],
        'from_name'   => $config['from_name'],
        'status'      => 'SMTP configured OK — visit /api/debug/resend-email/{orderId} to send a real order confirmation',
    ]);
});

// Temporary debug: manually resend confirmation email for a paid order
Route::get('/debug/resend-email/{orderId}', function (int $orderId) {
    $order = \App\Models\Order::with(['user','slot'])->find($orderId);
    if (! $order) {
        return response()->json(['error' => 'Order not found']);
    }
    if (! $order->paid_at) {
        return response()->json(['error' => 'Order not paid yet']);
    }
    if (! $order->user) {
        return response()->json(['error' => 'Order has no user attached', 'user_id' => $order->user_id]);
    }

    $fromEmail = \App\Models\Setting::query()->where('key', 'smtp_from_email')->value('value') ?: 'info@matrixmobiletyresandautos.com';
    $fromName  = \App\Models\Setting::query()->where('key', 'smtp_from_name')->value('value') ?: 'Matrix Mobile Tyres';
    $resendKey = config('services.resend.key') ?: env('RESEND_API_KEY');

    if (! $resendKey) {
        return response()->json(['error' => 'RESEND_API_KEY not set in Railway Variables']);
    }

    try {
        $fe = $fromEmail;
        $fn = $fromName;
        \Illuminate\Support\Facades\Mail::mailer('resend')
            ->to($fe)
            ->send(new class($fe, $fn) extends \Illuminate\Mail\Mailable {
                public function __construct(private string $fe, private string $fn) {}
                public function envelope(): \Illuminate\Mail\Mailables\Envelope {
                    return new \Illuminate\Mail\Mailables\Envelope(
                        from: new \Illuminate\Mail\Mailables\Address($this->fe, $this->fn),
                        subject: 'Matrix Tyres — Resend Test',
                    );
                }
                public function content(): \Illuminate\Mail\Mailables\Content {
                    return new \Illuminate\Mail\Mailables\Content(htmlString: '<h1>Resend is working!</h1><p>Email sending is configured correctly.</p>');
                }
                public function attachments(): array { return []; }
            });
        return response()->json(['status' => 'Resend test email sent to '.$fromEmail]);
    } catch (\Throwable $e) {
        return response()->json(['resend_error' => $e->getMessage(), 'from_email' => $fromEmail]);
    }
});


Route::post('/vehicle/lookup', [VehicleLookupController::class, 'lookup']);

Route::get('/public/slots', [CheckoutController::class, 'getSlots']);
Route::get('/public/slots/occupancy', [CheckoutController::class, 'getSlotOccupancy']);
Route::get('/public/checkout-config', [CheckoutController::class, 'checkoutConfig']);
Route::post('/public/reverse-geocode', [CheckoutController::class, 'reverseGeocode']);
Route::post('/public/delivery-quote', [CheckoutController::class, 'deliveryQuote']);
Route::post('/public/checkout', [CheckoutController::class, 'processCheckout']);
Route::get('/public/orders/{order}', [CheckoutController::class, 'showOrder'])->whereNumber('order');
Route::get('/public/contact', [PublicSettingsController::class, 'contact']);
Route::get('/public/brands', [PublicBrandController::class, 'index']);
Route::get('/public/tyre-types', [PublicTyreTypeController::class, 'index']);
Route::post('/public/orders/{order}/stripe-checkout', [CheckoutController::class, 'createStripeCheckout'])->whereNumber('order');
Route::post('/public/orders/{order}/stripe-confirm', [CheckoutController::class, 'confirmStripePayment'])->whereNumber('order');
Route::post('/public/stripe/webhook', [CheckoutController::class, 'stripeWebhook']);
Route::get('/public/tyres', [PublicTyreController::class, 'index']);
Route::get('/public/tyres/{id}', [PublicTyreController::class, 'show']);
Route::get('/public/tyre-search-options', [PublicTyreSearchOptionsController::class, 'index']);
Route::get('/public/reviews', [\App\Http\Controllers\PublicReviewsController::class, 'index']);

require __DIR__.'/api/customer.php';
require __DIR__.'/api/admin.php';
