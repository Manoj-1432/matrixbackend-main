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

// Temporary VDIM debug route — remove after confirming API works
Route::get('/debug/vdim', function () {
    $key = trim(env('VDIM_TIRE_API_KEY', ''));
    if ($key === '') {
        return response()->json(['error' => 'VDIM_TIRE_API_KEY not set in Railway Variables']);
    }
    $headers = ["x-api-key: {$key}", 'Accept: application/json'];
    $results = ['key_set' => substr($key, 0, 8) . '...'];

    $vdimGet = function (string $url) use ($headers, &$results): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['url' => $url, 'http_code' => $code, 'raw' => json_decode((string)$raw, true) ?? $raw];
    };

    // Test 1: by_vehicle/car
    $results['by_vehicle_car'] = $vdimGet('https://tire.vdim.app/api/v1/by_vehicle/car?year=2014&make=Vauxhall&model=Corsa');

    // Test 2: tire-dimensions (hyphen) without trim
    $results['tire_dimensions_no_trim'] = $vdimGet('https://tire.vdim.app/api/v1/tire-dimensions?year=2014&make=Vauxhall&model=Corsa');

    // Test 3: tire-dimensions with a guessed trim
    $results['tire_dimensions_base_trim'] = $vdimGet('https://tire.vdim.app/api/v1/tire-dimensions?year=2014&make=Vauxhall&model=Corsa&trim=Base');

    return response()->json($results);
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
