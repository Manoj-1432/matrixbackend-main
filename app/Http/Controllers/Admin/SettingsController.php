<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    use ApiResponse;

    // All settings keys managed by this panel
    private const SETTING_KEYS = [
        'brand_name',
        'logo_url',
        'website_title',
        'address',
        'contact_number',
        'contact_email',
        'vat_number',
        'vat_percentage',
        'vat_enabled',
        'platform_fee',
        'platform_fee_enabled',
        'tpms_charge',
        'tpms_charge_enabled',
        'maintenance_mode',
        'maintenance_message',
        'timezone',
        'country',
        'currency',
        'online_payment',
        'cash_on_delivery',
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_email',
        'smtp_from_name',
    ];

    /** GET /admin/settings — return all settings as key→value map */
    public function index(): JsonResponse
    {
        // Ensure all rows exist so the frontend always gets every key
        foreach (self::SETTING_KEYS as $key) {
            Setting::firstOrCreate(['key' => $key], ['value' => null]);
        }

        $rows = Setting::query()
            ->whereIn('key', self::SETTING_KEYS)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->key] = $row->value ?? '';
        }

        return $this->jsonSuccess(['settings' => $map]);
    }

    /** POST /admin/settings/logo — upload logo image, return public URL */
    public function uploadLogo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->allFiles(), [
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError(
                'Invalid file.',
                null,
                422,
                $validator->errors()->toArray()
            );
        }

        // Delete old logo if one exists
        $existing = Setting::where('key', 'logo_url')->value('value');
        if ($existing && str_contains($existing, '/storage/logos/')) {
            $relativePath = 'logos/' . basename($existing);
            Storage::disk('public')->delete($relativePath);
        }

        $file = $request->file('logo');
        $path = $file->store('logos', 'public');
        $url  = Storage::disk('public')->url($path);

        // Persist the new URL
        Setting::updateOrCreate(['key' => 'logo_url'], ['value' => $url]);

        return $this->jsonSuccess(['url' => $url], 'Logo uploaded successfully.');
    }

    /** POST /admin/settings — upsert all settings */
    public function store(Request $request): JsonResponse
    {
        return $this->upsertSettings($request);
    }

    /** PUT /admin/settings — upsert all settings (idempotent) */
    public function update(Request $request): JsonResponse
    {
        return $this->upsertSettings($request);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function upsertSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_name'           => ['required', 'string', 'max:255'],
            'logo_url'             => ['nullable', 'string', 'max:2048'],
            'website_title'        => ['nullable', 'string', 'max:255'],
            'address'              => ['nullable', 'string', 'max:1000'],
            'contact_number'       => ['nullable', 'string', 'max:50'],
            'contact_email'        => ['nullable', 'email', 'max:255'],
            'vat_number'           => ['nullable', 'string', 'max:255'],
            'vat_percentage'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_enabled'          => ['nullable', 'in:0,1'],
            'platform_fee'         => ['nullable', 'numeric', 'min:0'],
            'platform_fee_enabled' => ['nullable', 'in:0,1'],
            'tpms_charge'          => ['nullable', 'numeric', 'min:0'],
            'tpms_charge_enabled'  => ['nullable', 'in:0,1'],
            'maintenance_mode'     => ['nullable', 'in:0,1'],
            'maintenance_message'  => ['nullable', 'string', 'max:1000'],
            'timezone'             => ['nullable', 'string', 'max:255'],
            'country'              => ['nullable', 'string', 'max:255'],
            'currency'             => ['nullable', 'string', 'max:255'],
            'online_payment'       => ['nullable', 'in:0,1'],
            'cash_on_delivery'     => ['nullable', 'in:0,1'],
            'smtp_enabled'         => ['nullable', 'in:0,1'],
            'smtp_host'            => ['nullable', 'string', 'max:255'],
            'smtp_port'            => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_username'        => ['nullable', 'string', 'max:255'],
            'smtp_password'        => ['nullable', 'string', 'max:255'],
            'smtp_encryption'      => ['nullable', 'in:none,tls,ssl'],
            'smtp_from_email'      => ['nullable', 'email', 'max:255'],
            'smtp_from_name'       => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError(
                'Validation failed.',
                null,
                422,
                $validator->errors()->toArray()
            );
        }

        $map = [];
        foreach (self::SETTING_KEYS as $key) {
            $value = $request->input($key);
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value !== null ? (string) $value : null]
            );
            $map[$key] = $value ?? '';
        }

        return $this->jsonSuccess(
            ['settings' => $map],
            'Settings saved successfully.'
        );
    }
}
