<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ApiSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ApiSettingsController extends Controller
{
    use ApiResponse;

    // The four canonical API keys managed by this panel
    private const KNOWN_APIS = [
        [
            'key_name' => 'dvla',
            'label' => 'DVLA Vehicle Enquiry API',
            'description' => 'Look up UK vehicle registration details and MOT history via the DVLA database.',
            'icon_type' => 'dvla',
            'is_enabled' => true,
        ],
        [
            'key_name' => 'google_maps',
            'label' => 'Google Maps Platform',
            'description' => 'Geocoding, place search, and distance matrix for tyre fitting location services.',
            'icon_type' => 'maps',
            'is_enabled' => true,
        ],
        [
            'key_name' => 'openai',
            'label' => 'Workatmo Tyre Api',
            'description' => 'Tyre recommendations and related intelligence via the Workatmo tyre API.',
            'icon_type' => 'workatmo_tyre',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'paypal',
            'label' => 'PayPal Payments',
            'description' => 'Accept PayPal payments and manage refunds for tyre orders and bookings.',
            'icon_type' => 'paypal',
            'is_enabled' => true,
        ],
        [
            'key_name' => 'stripe_test',
            'label' => 'Stripe (Test)',
            'description' => 'Stripe test mode settings (secret/publishable/webhook).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'stripe_live',
            'label' => 'Stripe (Live)',
            'description' => 'Stripe live mode settings (secret/publishable/webhook).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'stripe_test_secret_key',
            'label' => 'Stripe Test Secret Key',
            'description' => 'Server-side secret key (starts with sk_test_).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'stripe_test_publishable_key',
            'label' => 'Stripe Test Publishable Key',
            'description' => 'Client-side publishable key (starts with pk_test_).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'stripe_test_webhook_secret',
            'label' => 'Stripe Test Webhook Secret',
            'description' => 'Webhook signing secret for test endpoints (starts with whsec_).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'stripe_live_secret_key',
            'label' => 'Stripe Live Secret Key',
            'description' => 'Server-side secret key (starts with sk_live_).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'stripe_live_publishable_key',
            'label' => 'Stripe Live Publishable Key',
            'description' => 'Client-side publishable key (starts with pk_live_).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'stripe_live_webhook_secret',
            'label' => 'Stripe Live Webhook Secret',
            'description' => 'Webhook signing secret for live endpoints (starts with whsec_).',
            'icon_type' => 'stripe',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'brand_ai_generate',
            'label' => 'Brands Generate with AI',
            'description' => 'Toggle Brand AI generation button visibility and usage in admin.',
            'icon_type' => 'workatmo_tyre',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'size_ai_generate',
            'label' => 'Sizes Generate with AI',
            'description' => 'Toggle Size AI generation button visibility and usage in admin.',
            'icon_type' => 'workatmo_tyre',
            'is_enabled' => false,
        ],
        [
            'key_name' => 'tyre_description_ai_generate',
            'label' => 'Tyre Description Generate with AI',
            'description' => 'Toggle AI description generation button visibility and usage on tyre create/edit.',
            'icon_type' => 'workatmo_tyre',
            'is_enabled' => false,
        ],
    ];

    /** GET /admin/api-settings */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('api_settings')) {
            return $this->jsonError('You do not have permission to view API settings.', null, 403);
        }
        // Ensure all rows exist; use raw DB insert to avoid encrypted-cast issues
        foreach (self::KNOWN_APIS as $api) {
            \Illuminate\Support\Facades\DB::table('api_settings')->upsert(
                [[
                    'key_name'    => $api['key_name'],
                    'label'       => $api['label'],
                    'description' => $api['description'],
                    'icon_type'   => $api['icon_type'],
                    'is_enabled'  => $api['is_enabled'],
                    'value'       => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]],
                ['key_name'],
                ['label', 'description', 'icon_type', 'updated_at']
            );
        }

        $keyOrder = array_column(self::KNOWN_APIS, 'key_name');
        $settings = ApiSetting::query()
            ->whereIn('key_name', $keyOrder)
            ->get()
            ->sortBy(fn ($s) => array_search($s->key_name, $keyOrder, true))
            ->values();

        return $this->jsonSuccess([
            'settings' => $settings->map(fn (ApiSetting $s) => $this->settingResource($s)),
        ]);
    }

    /** PUT /admin/api-settings/{id} — update key value */
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('api_settings')) {
            return $this->jsonError('You do not have permission to manage API settings.', null, 403);
        }

        $setting = ApiSetting::query()->find($id);

        if (! $setting) {
            return $this->jsonError('API setting not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'value' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $setting->value = $request->input('value');
        $setting->save();

        return $this->jsonSuccess($this->settingResource($setting), 'API key updated successfully.');
    }

    /** PATCH /admin/api-settings/{id}/toggle — flip is_enabled */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('api_settings')) {
            return $this->jsonError('You do not have permission to manage API settings.', null, 403);
        }

        $setting = ApiSetting::query()->find($id);

        if (! $setting) {
            return $this->jsonError('API setting not found.', null, 404);
        }

        $setting->is_enabled = ! $setting->is_enabled;
        $setting->save();

        return $this->jsonSuccess($this->settingResource($setting), 'API setting toggled successfully.');
    }

    /** POST /admin/stripe/test — validate Stripe secret key works */
    public function testStripe(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('api_settings')) {
            return $this->jsonError('You do not have permission to manage API settings.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'mode' => ['required', 'string', 'in:test,live'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $mode = (string) $request->input('mode');
        $keyName = $mode === 'live' ? 'stripe_live_secret_key' : 'stripe_test_secret_key';

        $setting = ApiSetting::query()->where('key_name', $keyName)->first();
        $secret = $setting?->value ? trim((string) $setting->value) : '';
        if ($secret === '') {
            return $this->jsonError("Stripe {$mode} secret key is not set.", null, 422);
        }
        if (! str_starts_with($secret, $mode === 'live' ? 'sk_live_' : 'sk_test_')) {
            return $this->jsonError("Stripe {$mode} secret key format looks invalid.", null, 422);
        }

        try {
            $res = Http::withBasicAuth($secret, '')
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.stripe.com/v1/account');

            if (! $res->successful()) {
                $payload = $res->json();
                $msg = is_array($payload) && isset($payload['error']['message'])
                    ? (string) $payload['error']['message']
                    : "Stripe request failed (HTTP {$res->status()}).";
                return $this->jsonError($msg, null, 422);
            }

            $data = $res->json();
            return $this->jsonSuccess([
                'mode' => $mode,
                'account_id' => is_array($data) && isset($data['id']) ? (string) $data['id'] : null,
                'livemode' => is_array($data) && isset($data['livemode']) ? (bool) $data['livemode'] : null,
            ], 'Stripe connection OK.');
        } catch (\Throwable $e) {
            return $this->jsonError('Stripe test failed: '.$e->getMessage(), null, 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settingResource(ApiSetting $setting): array
    {
        $val = $setting->value;

        return [
            'id'          => $setting->id,
            'key_name'    => $setting->key_name,
            'label'       => $setting->label,
            'description' => $setting->description,
            'icon_type'   => $setting->icon_type,
            'value'       => $val ? '••••••••••••••••••••••••'.substr((string) $val, -4) : null,
            'has_key'     => ! empty($val),
            'is_enabled'  => $setting->is_enabled,
            'updated_at'  => $setting->updated_at?->toIso8601String(),
        ];
    }
}
