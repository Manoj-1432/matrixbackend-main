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

    $resendKey = config('services.resend.key') ?: env('RESEND_API_KEY');

    // Debug: try Resend directly without going through AdminSmtpMailer
    if ($resendKey) {
        try {
            \Illuminate\Support\Facades\Mail::mailer('resend')
                ->to('info@matrixmobiletyresandautos.com')
                ->send(new class extends \Illuminate\Mail\Mailable {
                    public function envelope(): \Illuminate\Mail\Mailables\Envelope {
                        return new \Illuminate\Mail\Mailables\Envelope(subject: 'Matrix Tyres — Resend Test');
                    }
                    public function content(): \Illuminate\Mail\Mailables\Content {
                        return new \Illuminate\Mail\Mailables\Content(htmlString: '<h1>Resend is working!</h1>');
                    }
                    public function attachments(): array { return []; }
                });
            return response()->json(['resend_key_present' => true, 'status' => 'Resend test email sent!']);
        } catch (\Throwable $e) {
            return response()->json(['resend_key_present' => true, 'resend_error' => $e->getMessage()]);
        }
    }

    return response()->json(['resend_key_present' => false, 'env_check' => env('RESEND_API_KEY') ? 'set' : 'missing']);

    $mailer = app(\App\Services\AdminSmtpMailer::class);
    $smtpConfig = $mailer->resolveSmtpConfig();
    if ($smtpConfig === null) {
        return response()->json(['error' => 'SMTP resolveSmtpConfig() returned null — settings not saved correctly']);
    }

    try {
        $mailable = new \App\Mail\CheckoutConfirmationMail(
            $order,
            new \Illuminate\Mail\Mailables\Address($smtpConfig['from_email'], $smtpConfig['from_name']),
            'test-password-123'
        );
        $mailer->sendTo($order->user->email, $mailable);
        $order->update(['confirmation_email_sent_at' => now()]);
    } catch (\Throwable $e) {
        return response()->json([
            'error'   => $e->getMessage(),
            'class'   => get_class($e),
            'file'    => $e->getFile().':'.$e->getLine(),
        ]);
    }

    return response()->json([
        'order_id'   => $order->id,
        'user_email' => $order->user->email,
        'result'     => 'email sent successfully',
    ]);
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
