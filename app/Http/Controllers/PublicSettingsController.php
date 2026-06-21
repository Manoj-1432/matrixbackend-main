<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class PublicSettingsController extends Controller
{
    use ApiResponse;

    public function contact(): JsonResponse
    {
        $settings = Setting::query()
            ->whereIn('key', ['contact_number', 'contact_email', 'address', 'brand_name', 'logo_url'])
            ->pluck('value', 'key');

        $contactNumber = $settings->get('contact_number');
        $contactEmail = $settings->get('contact_email');
        $address = $settings->get('address');
        $brandName = $settings->get('brand_name');
        $logoUrl = $settings->get('logo_url');

        return $this->jsonSuccess([
            'contact_number' => is_string($contactNumber) ? trim($contactNumber) : '',
            'contact_email' => is_string($contactEmail) ? trim($contactEmail) : '',
            'address' => is_string($address) ? trim($address) : '',
            'brand_name' => is_string($brandName) ? trim($brandName) : '',
            'logo_url' => is_string($logoUrl) ? trim($logoUrl) : '',
        ]);
    }
}
