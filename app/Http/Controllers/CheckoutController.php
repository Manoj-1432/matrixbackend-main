<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Rules\StripeCheckoutRedirectUrl;
use App\Models\ApiSetting;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Slot;
use App\Models\User;
use App\Services\DeliveryChargeService;
use App\Services\OrderConfirmationEmailService;
use App\Services\StripeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;
use Stripe\Webhook;

class CheckoutController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderConfirmationEmailService $orderConfirmationEmailService,
        private readonly DeliveryChargeService $deliveryChargeService,
    ) {}

    /** GET /booking */
    public function bookingPage(): \Illuminate\Contracts\View\View
    {
        $nonce = base64_encode(random_bytes(16));
        $minDate = Carbon::tomorrow()->toDateString();

        return view('checkout.booking', compact('nonce', 'minDate'));
    }

    /** GET /checkout/success */
    public function successPage(Request $request): \Illuminate\Contracts\View\View|\Illuminate\Http\Response
    {
        $orderId = (int) $request->query('order_id', 0);
        $order = $orderId > 0 ? Order::query()->find($orderId) : null;

        if (! $order) {
            abort(404, 'Order not found.');
        }

        $currencySymbol = match (strtoupper((string) (Setting::query()->where('key', 'currency')->value('value') ?? 'GBP'))) {
            'USD' => '$',
            'EUR' => '€',
            default => '£',
        };

        $homeUrl = e(rtrim((string) config('workatmo.frontend_url', 'http://localhost:3000'), '/'));
        $nonce = base64_encode(random_bytes(16));

        return view('checkout.success', compact('order', 'currencySymbol', 'homeUrl', 'nonce'));
    }

    /** GET /public/checkout-config */
    public function checkoutConfig(): JsonResponse
    {
        [
            $vatEnabled,
            $vatPercentage,
            $platformFeeEnabled,
            $platformFee,
            $tpmsChargeEnabled,
            $tpmsCharge,
            $currency,
        ] = $this->checkoutConfigValues();

        $googleMaps = ApiSetting::query()->where('key_name', 'google_maps')->first();
        $mapsLocationEnabled = $googleMaps
            && $googleMaps->is_enabled
            && trim((string) $googleMaps->value) !== '';

        return $this->jsonSuccess([
            'vat_enabled' => $vatEnabled,
            'vat_percentage' => $vatPercentage,
            'platform_fee_enabled' => $platformFeeEnabled,
            'platform_fee' => $platformFee,
            'tpms_charge_enabled' => $tpmsChargeEnabled,
            'tpms_charge' => $tpmsCharge,
            'currency' => $currency,
            'maps_location_enabled' => $mapsLocationEnabled,
            'min_fitting_date' => Carbon::tomorrow()->toDateString(),
        ]);
    }

    /**
     * Resolve browser GPS coordinates to address fields using the Google Maps Geocoding API
     * and the Google Maps API key from admin API settings.
     *
     * POST /public/reverse-geocode
     */
    public function reverseGeocode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $setting = ApiSetting::query()->where('key_name', 'google_maps')->first();
        if (! $setting || ! $setting->is_enabled) {
            return $this->jsonError('Location services are not configured.', null, 503);
        }

        $apiKey = trim((string) $setting->value);
        if ($apiKey === '') {
            return $this->jsonError('Location services are not configured.', null, 503);
        }

        $lat = (float) $validator->validated()['latitude'];
        $lng = (float) $validator->validated()['longitude'];

        try {
            $response = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng' => "{$lat},{$lng}",
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::error('Reverse geocode request failed: '.$e->getMessage());

            return $this->jsonError('Could not reach location services.', null, 502);
        }

        if (! $response->ok()) {
            return $this->jsonError('Location lookup failed.', null, 502);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? '');

        if ($status === 'ZERO_RESULTS' || empty($payload['results'][0])) {
            return $this->jsonError('No address found for this location.', null, 422);
        }

        if ($status !== 'OK') {
            Log::warning('Google Geocoding API returned non-OK status.', ['status' => $status]);

            return $this->jsonError('Could not resolve this location to an address.', null, 422);
        }

        $first = $payload['results'][0];
        $components = $first['address_components'] ?? null;
        if (! is_array($components)) {
            return $this->jsonError('Invalid geocoder response.', null, 502);
        }

        $formatted = is_string($first['formatted_address'] ?? null) ? $first['formatted_address'] : '';

        [$address, $city, $postcode] = $this->parseGeocodeComponents($components, $formatted);

        return $this->jsonSuccess([
            'address' => $address,
            'city' => $city,
            'postcode' => $postcode,
        ]);
    }

    /**
     * Quote delivery charge from business address to customer fitting address.
     *
     * POST /public/delivery-quote
     */
    public function deliveryQuote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'postcode' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $postcode = $validator->validated()['postcode'];

        try {
            $quote = $this->deliveryChargeService->quoteForPostcode($postcode);
        } catch (\RuntimeException $e) {
            $status = $e->getCode();
            if (! is_int($status) || $status < 400 || $status > 599) {
                $status = 422;
            }

            return $this->jsonError($e->getMessage(), null, $status);
        }

        return $this->jsonSuccess($quote);
    }

    /** GET /public/orders/{order} */
    public function showOrder(Order $order): JsonResponse
    {
        $isPaid = $this->isOrderPaid($order);

        return $this->jsonSuccess([
            'order' => [
                'id' => $order->id,
                'amount' => $order->amount,
                'status' => $order->status,
                'payment_provider' => $order->payment_provider,
                'payment_status' => $order->payment_status,
                'paid_at' => $order->paid_at?->toIso8601String(),
                'is_paid' => $isPaid,
            ],
        ]);
    }

    /**
     * Get active slots.
     */
    public function getSlots(): JsonResponse
    {
        $slots = Slot::query()
            ->where('status', 'active')
            ->orderByRaw("FIELD(day, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
            ->orderBy('start_time')
            ->get();

        return $this->jsonSuccess(['slots' => $slots]);
    }

    /**
     * Calendar slot occupancy for public checkout (slot_id + fitting_date).
     * GET /public/slots/occupancy?from=Y-m-d&to=Y-m-d
     */
    public function getSlotOccupancy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $from = Carbon::parse($validator->validated()['from'])->startOfDay();
        $to = Carbon::parse($validator->validated()['to'])->startOfDay();

        if ($from->gt($to)) {
            return $this->jsonError('Invalid range: from must be on or before to.', null, 422);
        }

        if ($from->diffInDays($to) > 31) {
            return $this->jsonError('Date range must be at most 31 days.', null, 422);
        }

        $occupancy = Order::query()
            ->blocksFittingSlot()
            ->whereBetween('fitting_date', [$from->toDateString(), $to->toDateString()])
            ->select(['slot_id', 'fitting_date'])
            ->get()
            ->map(fn (Order $o): array => [
                'slot_id' => (int) $o->slot_id,
                'date' => $o->fitting_date?->format('Y-m-d') ?? '',
            ])
            ->values()
            ->all();

        return $this->jsonSuccess(['occupancy' => $occupancy]);
    }

    /**
     * Process checkout.
     */
    public function processCheckout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:30',
            'address' => 'required|string|max:1000',
            'city' => 'required|string|max:100',
            'postcode' => 'required|string|max:20',
            'customer_comment' => 'nullable|string|max:2000',

            'slot_id' => 'required|exists:slots,id',
            'fitting_date' => 'required|date_format:Y-m-d|after:today',

            'vehicle_registration' => 'nullable|string|max:50',
            'vehicle_make' => 'nullable|string|max:100',
            'vehicle_model' => 'nullable|string|max:100',

            'tyre_brand' => 'required|string',
            'tyre_model' => 'required|string',
            'tyre_size' => 'required|string',
            'tyre_quantity' => 'required|integer|min:1',
            'tyre_price' => 'nullable|numeric|min:0',
            'tyre_unit_price' => 'nullable|numeric|min:0',
            'include_tpms' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $fullName = trim($data['first_name'].' '.$data['last_name']);
        $vehicleRegistrationRaw = trim((string) ($data['vehicle_registration'] ?? ''));
        $vehicleRegistration = $vehicleRegistrationRaw !== ''
            ? mb_strtoupper($vehicleRegistrationRaw)
            : null;
        $customerComment = trim((string) ($data['customer_comment'] ?? ''));
        $customerComment = $customerComment !== '' ? $customerComment : null;

        $rawUnitPrice = $data['tyre_unit_price'] ?? $data['tyre_price'] ?? null;
        if ($rawUnitPrice === null) {
            return $this->jsonError('Validation failed.', null, 422, [
                'tyre_unit_price' => ['The tyre unit price field is required.'],
            ]);
        }

        $unitPrice = round((float) $rawUnitPrice, 2);
        $quantity = (int) $data['tyre_quantity'];
        $includeTpms = $request->boolean('include_tpms');
        [
            $vatEnabled,
            $vatPercentage,
            $platformFeeEnabled,
            $platformFee,
            $tpmsChargeEnabled,
            $tpmsCharge,
            $currency,
        ] = $this->checkoutConfigValues();
        try {
            $deliveryQuote = $this->deliveryChargeService->quoteForCustomerAddress(
                $data['address'],
                $data['city'],
                $data['postcode'],
            );
        } catch (\RuntimeException $e) {
            $status = $e->getCode();
            if (! is_int($status) || $status < 400 || $status > 599) {
                $status = 422;
            }

            return $this->jsonError($e->getMessage(), null, $status);
        }

        $deliveryCharge = round((float) $deliveryQuote['delivery_charge'], 2);
        $deliveryDistanceMiles = (float) $deliveryQuote['distance_miles'];
        $deliveryOutOfRange = (bool) $deliveryQuote['out_of_range'];

        $subtotal = round($unitPrice * $quantity, 2);
        $appliedPlatformFee = $platformFeeEnabled ? $platformFee : 0.0;
        $appliedTpmsCharge = ($tpmsChargeEnabled && $includeTpms) ? $tpmsCharge : 0.0;
        $taxBase = round($subtotal + $appliedPlatformFee + $appliedTpmsCharge + $deliveryCharge, 2);
        $vatAmount = $vatEnabled ? round(($taxBase * $vatPercentage) / 100, 2) : 0.0;
        $total = round($subtotal + $appliedPlatformFee + $appliedTpmsCharge + $deliveryCharge + $vatAmount, 2);

        $fittingDateCarbon = Carbon::parse($data['fitting_date'])->startOfDay();
        $fittingDateString = $fittingDateCarbon->toDateString();

        try {
            DB::beginTransaction();

            $slot = Slot::query()
                ->where('id', (int) $data['slot_id'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $slot) {
                DB::rollBack();

                return $this->jsonError('Invalid or inactive slot.', null, 422);
            }

            $dayFromDate = strtolower($fittingDateCarbon->format('l'));
            $slotDay = strtolower((string) $slot->day);
            if ($dayFromDate !== $slotDay) {
                DB::rollBack();

                return $this->jsonError('Date does not match this slot\'s day of week.', null, 422, [
                    'fitting_date' => ['The selected date does not fall on the same weekday as this slot.'],
                ]);
            }

$slotTakenByPaidOrder = Order::query()
                ->forFittingSlotOnDate((int) $slot->id, $fittingDateString)
                ->blocksFittingSlot()
                ->lockForUpdate()
                ->exists();

            if ($slotTakenByPaidOrder) {
                DB::rollBack();

                return $this->jsonError('Slot already booked', null, 422);
            }

            // 1. Find or create user
            $user = User::where(['email' => $data['email']])->first();
            $isNewUser = false;
            $generatedPassword = null;

            if (! $user) {
                $userRoleId = Role::where('name', Role::USER)->value('id');
                $generatedPassword = Str::password(14);

                $user = User::create([
                    'name' => $fullName,
                    'email' => $data['email'],
                    'password' => $generatedPassword,
                    'is_active' => true,
                    'role_id' => $userRoleId,
                ]);

                $isNewUser = true;
            }

            // Update user details in case they changed
            $user->update([
                'phone' => $data['phone'],
                'address' => $data['address'],
                'city' => $data['city'],
                'postcode' => $data['postcode'],
                'vehicle_registration_number' => $vehicleRegistration,
                'name' => $fullName,
            ]);

            // 2. Create Order
            $serviceType = "Tyre Fitting: {$data['tyre_brand']} {$data['tyre_model']} ({$data['tyre_size']})";

            $fittingAddressBlock = "Fitting address:\n{$data['address']}\n{$data['city']}\n{$data['postcode']}";
            $commentBlock = $customerComment !== null
                ? "Customer comment:\n{$customerComment}\n"
                : '';

            $order = Order::create([
                'user_id' => $user->id,
                'slot_id' => $data['slot_id'],
                'fitting_date' => $fittingDateString,
                'vehicle_registration' => $vehicleRegistration,
                'vehicle_make' => $data['vehicle_make'] ?? '',
                'vehicle_model' => $data['vehicle_model'] ?? '',
                'service_type' => $serviceType,
                'tyre_brand' => $data['tyre_brand'],
                'tyre_model' => $data['tyre_model'],
                'tyre_size' => $data['tyre_size'],
                'tyre_quantity' => $quantity,
                'amount' => $total,
                'delivery_charge' => $deliveryCharge,
                'delivery_distance_miles' => $deliveryDistanceMiles,
                'payment_provider' => 'stripe',
                'payment_status' => 'unpaid',
                'status' => 'pending',
                'customer_comment' => $customerComment,
                'is_new_user' => $isNewUser,
                'new_user_password_encrypted' => $isNewUser && $generatedPassword
                    ? Crypt::encryptString($generatedPassword)
                    : null,
                'notes' => "{$commentBlock}{$fittingAddressBlock}\nSubtotal: {$subtotal}\nPlatform Fee Enabled: ".($platformFeeEnabled ? '1' : '0')."\nPlatform Fee Amount: {$appliedPlatformFee}\nTPMS Charge Enabled: ".($tpmsChargeEnabled ? '1' : '0')."\nCustomer TPMS add-on: ".($includeTpms ? 'yes' : 'no')."\nTPMS Charge Amount: {$appliedTpmsCharge}\nDelivery Distance Miles: {$deliveryDistanceMiles}\nDelivery Charge: {$deliveryCharge}\nDelivery Out Of Range: ".($deliveryOutOfRange ? 'yes' : 'no')."\nTax Base: {$taxBase}\nVAT Enabled: ".($vatEnabled ? '1' : '0')."\nVAT Percentage: {$vatPercentage}\nVAT Amount: {$vatAmount}\nCurrency: {$currency}\nTotal: {$total}",
            ]);

            DB::commit();

            return $this->jsonSuccess([
                'order' => $order,
                'summary' => [
                    'currency' => $currency,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'platform_fee_enabled' => $platformFeeEnabled,
                    'platform_fee' => $appliedPlatformFee,
                    'tpms_charge_enabled' => $tpmsChargeEnabled,
                    'include_tpms' => $includeTpms,
                    'tpms_charge' => $appliedTpmsCharge,
                    'delivery_distance_miles' => $deliveryDistanceMiles,
                    'delivery_charge' => $deliveryCharge,
                    'delivery_out_of_range' => $deliveryOutOfRange,
                    'tax_base' => $taxBase,
                    'vat_enabled' => $vatEnabled,
                    'vat_percentage' => $vatPercentage,
                    'vat_amount' => $vatAmount,
                    'total' => $total,
                ],
            ], 'Booking successful!', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Checkout failed: '.$e->getMessage());

            return $this->jsonError('Failed to process booking. Please try again.', null, 500);
        }
    }

    /**
     * Create a Stripe Checkout Session for an existing order (public).
     * POST /public/orders/{order}/stripe-checkout
     */
    public function createStripeCheckout(Request $request, Order $order, StripeSettings $settings): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['nullable', 'string', 'in:test,live'],
            'success_url' => ['nullable', 'string', new StripeCheckoutRedirectUrl],
            'cancel_url' => ['nullable', 'string', new StripeCheckoutRedirectUrl],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $requested = $request->input('mode');
        $mode = ($requested === 'test' || $requested === 'live')
            ? (string) $requested
            : $settings->resolveCheckoutMode();
        if (! $settings->enabled($mode)) {
            return $this->jsonError('Stripe is disabled in API settings.', null, 422);
        }

        $secret = $settings->secretKey($mode);
        if (! $secret) {
            return $this->jsonError("Stripe {$mode} secret key is not set.", null, 422);
        }

        if ($order->payment_status === 'paid') {
            return $this->jsonSuccess([
                'order_id' => $order->id,
                'already_paid' => true,
                'url' => null,
            ], 'Order is already paid.');
        }

        $frontendBase = (string) config('workatmo.frontend_url', 'http://localhost:3000');
        $successUrl = (string) ($request->input('success_url') ?: rtrim($frontendBase, '/')."/checkout/success?order_id={$order->id}&session_id={CHECKOUT_SESSION_ID}");
        $cancelUrl = (string) ($request->input('cancel_url') ?: rtrim($frontendBase, '/')."/checkout/cancel?order_id={$order->id}");

        try {
            Stripe::setApiKey($secret);

            $session = StripeCheckoutSession::create([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => (string) $order->id,
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'gbp',
                        'unit_amount' => (int) round(((float) $order->amount) * 100),
                        'product_data' => [
                            'name' => 'Booking',
                            'description' => $order->service_type,
                        ],
                    ],
                ]],
            ]);

            $order->update([
                'stripe_mode' => $mode,
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : null,
            ]);

            return $this->jsonSuccess([
                'order_id' => $order->id,
                'url' => $session->url,
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout session creation failed: '.$e->getMessage());

            return $this->jsonError('Failed to start Stripe checkout: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Stripe webhook endpoint.
     * POST /public/stripe/webhook
     */
    public function stripeWebhook(Request $request, StripeSettings $settings): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature', '');

        // Try both modes; verify signature with whichever matches.
        $event = null;
        $modeUsed = null;

        foreach (['live', 'test'] as $mode) {
            $secret = $settings->webhookSecret($mode);
            if (! $secret) {
                continue;
            }
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $secret);
                $modeUsed = $mode;
                break;
            } catch (\Throwable) {
                // keep trying
            }
        }

        if (! $event || ! $modeUsed) {
            return $this->jsonError('Invalid webhook signature.', null, 400);
        }

        try {
            if ($event->type === 'checkout.session.completed') {
                /** @var Session $session */
                $session = $event->data->object;
                $orderId = $session->metadata['order_id'] ?? $session->client_reference_id ?? null;
                if (! $orderId) {
                    return $this->jsonSuccess(['ignored' => true], 'Missing order_id.');
                }

                $order = Order::query()->find((int) $orderId);
                if (! $order) {
                    return $this->jsonSuccess(['ignored' => true], 'Order not found.');
                }

                $sessionPaymentStatus = (string) ($session->payment_status ?? '');
                if (in_array($sessionPaymentStatus, ['paid', 'no_payment_required'], true)) {
                    $this->markOrderPaidFromStripeSession($order, $session, $modeUsed);
                }
            }

            return $this->jsonSuccess(['received' => true]);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handling failed: '.$e->getMessage());

            return $this->jsonError('Webhook handler error.', null, 500);
        }
    }

    /**
     * Confirm Stripe payment for an order as a fallback when webhook is delayed/missed.
     * POST /public/orders/{order}/stripe-confirm
     */
    public function confirmStripePayment(Request $request, Order $order, StripeSettings $settings): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_id' => ['nullable', 'string'],
            'mode' => ['nullable', 'string', 'in:test,live'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        if ($this->isOrderPaid($order)) {
            return $this->jsonSuccess(['order_id' => $order->id, 'paid' => true], 'Order is already marked paid.');
        }

        $preferredMode = $request->input('mode');
        $preferredMode = ($preferredMode === 'test' || $preferredMode === 'live')
            ? (string) $preferredMode
            : (in_array((string) $order->stripe_mode, ['test', 'live'], true) ? (string) $order->stripe_mode : null);

        $sessionId = (string) ($request->input('session_id') ?: ($order->stripe_checkout_session_id ?: ''));
        if ($sessionId === '') {
            return $this->jsonError('Missing Stripe session id for payment confirmation.', null, 422);
        }

        try {
            [$session, $mode] = $settings->retrieveCheckoutSession($sessionId, $preferredMode);

            $sessionPaymentStatus = (string) ($session->payment_status ?? '');
            if (! in_array($sessionPaymentStatus, ['paid', 'no_payment_required'], true)) {
                return $this->jsonSuccess([
                    'order_id' => $order->id,
                    'paid' => false,
                    'stripe_payment_status' => $sessionPaymentStatus,
                ], 'Payment not completed yet.');
            }

            $this->markOrderPaidFromStripeSession($order, $session, $mode);

            return $this->jsonSuccess([
                'order_id' => $order->id,
                'paid' => true,
                'stripe_payment_status' => $sessionPaymentStatus,
            ], 'Payment confirmed.');
        } catch (\Throwable $e) {
            Log::error('Stripe payment confirmation failed: '.$e->getMessage());

            return $this->jsonError('Unable to confirm Stripe payment.', null, 500);
        }
    }

    /**
     * @return array{0: bool, 1: float, 2: bool, 3: float, 4: bool, 5: float, 6: string}
     */
    private function checkoutConfigValues(): array
    {
        $vatEnabledRaw = (string) (Setting::query()->where('key', 'vat_enabled')->value('value') ?? '0');
        $vatPercentageRaw = Setting::query()->where('key', 'vat_percentage')->value('value');
        $platformFeeEnabledRaw = (string) (Setting::query()->where('key', 'platform_fee_enabled')->value('value') ?? '0');
        $platformFeeRaw = Setting::query()->where('key', 'platform_fee')->value('value');
        $tpmsChargeEnabledRaw = (string) (Setting::query()->where('key', 'tpms_charge_enabled')->value('value') ?? '0');
        $tpmsChargeRaw = Setting::query()->where('key', 'tpms_charge')->value('value');
        $currencyRaw = (string) (Setting::query()->where('key', 'currency')->value('value') ?? 'GBP');

        $vatEnabled = $vatEnabledRaw === '1';
        $vatPercentage = is_numeric($vatPercentageRaw) ? max(0.0, min(100.0, (float) $vatPercentageRaw)) : 0.0;
        $platformFeeEnabled = $platformFeeEnabledRaw === '1';
        $platformFee = is_numeric($platformFeeRaw) ? max(0.0, (float) $platformFeeRaw) : 0.0;
        $tpmsChargeEnabled = $tpmsChargeEnabledRaw === '1';
        $tpmsCharge = is_numeric($tpmsChargeRaw) ? max(0.0, (float) $tpmsChargeRaw) : 0.0;
        $currency = trim($currencyRaw) !== '' ? strtoupper(trim($currencyRaw)) : 'GBP';

        return [$vatEnabled, $vatPercentage, $platformFeeEnabled, $platformFee, $tpmsChargeEnabled, $tpmsCharge, $currency];
    }

    /**
     * @param  array<int, mixed>  $components
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseGeocodeComponents(array $components, string $formattedAddress): array
    {
        $findFirst = function (array $typePriority) use ($components): string {
            foreach ($typePriority as $type) {
                foreach ($components as $c) {
                    if (! is_array($c)) {
                        continue;
                    }
                    $types = $c['types'] ?? null;
                    if (! is_array($types)) {
                        continue;
                    }
                    if (! in_array($type, $types, true)) {
                        continue;
                    }
                    $long = $c['long_name'] ?? '';
                    if (is_string($long) && $long !== '') {
                        return $long;
                    }
                }
            }

            return '';
        };

        $streetNumber = $findFirst(['street_number']);
        $route = $findFirst(['route']);
        $line1 = trim($streetNumber.' '.$route);

        $subpremise = $findFirst(['subpremise']);
        if ($subpremise !== '' && $line1 !== '') {
            $line1 = $subpremise.', '.$line1;
        } elseif ($subpremise !== '') {
            $line1 = $subpremise;
        }

        $city = $findFirst([
            'locality',
            'postal_town',
            'administrative_area_level_2',
            'sublocality',
            'sublocality_level_1',
        ]);

        $postcode = $findFirst(['postal_code']);

        if ($line1 === '' && $formattedAddress !== '') {
            $parts = array_map('trim', explode(',', $formattedAddress));
            $line1 = $parts[0] ?? '';
        }

        return [$line1, $city, $postcode];
    }

    private function isOrderPaid(Order $order): bool
    {
        if ($order->paid_at) {
            return true;
        }

        $paymentStatus = strtolower((string) ($order->payment_status ?? ''));
        if (in_array($paymentStatus, ['paid', 'succeeded', 'completed', 'captured', 'no_payment_required'], true)) {
            return true;
        }

        return $order->status === 'completed';
    }

    private function markOrderPaidFromStripeSession(Order $order, StripeCheckoutSession $session, string $mode): void
    {
        if ($this->isOrderPaid($order)) {
            return;
        }

        DB::transaction(function () use ($order, $session, $mode): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $this->isOrderPaid($locked)) {
                return;
            }

            $stripeFields = [
                'payment_provider' => 'stripe',
                'payment_status' => 'paid',
                'stripe_mode' => $mode,
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : $locked->stripe_payment_intent_id,
                'paid_at' => now(),
            ];

            $slotId = $locked->slot_id !== null ? (int) $locked->slot_id : null;
            $fittingDate = $locked->fitting_date?->format('Y-m-d');

            if ($slotId !== null && $fittingDate !== null && $fittingDate !== '') {
                Order::query()
                    ->forFittingSlotOnDate($slotId, $fittingDate)
                    ->blocksFittingSlot()
                    ->where('id', '!=', $locked->id)
                    ->lockForUpdate()
                    ->get();

                if (Order::fittingSlotTakenByAnotherPaidOrder($slotId, $fittingDate, $locked->id)) {
                    $conflictNote = '[Auto] Fitting slot was already booked when payment completed. Refund may be required.';
                    $notes = trim((string) ($locked->notes ?? ''));
                    $locked->update(array_merge($stripeFields, [
                        'status' => 'cancelled',
                        'notes' => $notes !== '' ? "{$notes}\n{$conflictNote}" : $conflictNote,
                    ]));
                    Log::warning('Stripe payment received but fitting slot already taken.', [
                        'order_id' => $locked->id,
                        'slot_id' => $slotId,
                        'fitting_date' => $fittingDate,
                    ]);

                    return;
                }
            }

            $locked->update(array_merge($stripeFields, [
                'status' => 'processing',
            ]));

            $this->orderConfirmationEmailService->sendOnceForPaidOrder($locked->id);
        });
    }
}
