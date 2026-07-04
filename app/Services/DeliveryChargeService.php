<?php

namespace App\Services;

use App\Models\ApiSetting;
use App\Models\DeliveryCharge;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliveryChargeService
{
    private const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    private const DISTANCE_MATRIX_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    private const POSTCODES_IO_URL = 'https://api.postcodes.io/postcodes';

    private const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving';

    private const METERS_PER_MILE = 1609.344;

    /**
     * @return array{
     *     distance_miles: float,
     *     delivery_charge: float,
     *     matched_tier: array{from: float, to: float, charge: float}|null,
     *     out_of_range: bool
     * }
     *
     * @throws \RuntimeException
     */
    public function quoteForCustomerAddress(string $address, string $city, string $postcode): array
    {
        $businessAddress = $this->getBusinessAddress();
        if ($businessAddress === '') {
            throw new \RuntimeException('Business address is not configured.', 503);
        }

        $apiKey = $this->resolveGoogleMapsApiKey();
        $customerAddress = $this->composeAddressLine($address, $city, $postcode);

        $origin = $this->geocodeAddress($businessAddress, $apiKey, 'business');
        $destination = $this->geocodeAddress($customerAddress, $apiKey, 'customer');

        $distanceMiles = $this->drivingDistanceMiles(
            $origin['lat'],
            $origin['lng'],
            $destination['lat'],
            $destination['lng'],
            $apiKey
        );

        return $this->buildQuoteResult($distanceMiles);
    }

    /**
     * Quote delivery using only a postcode via postcodes.io (no API key required).
     * Uses Haversine straight-line distance from the business postcode.
     *
     * @return array{distance_miles: float, delivery_charge: float, matched_tier: array|null, out_of_range: bool}
     * @throws \RuntimeException
     */
    public function quoteForPostcode(string $postcode): array
    {
        $postcode = trim(strtoupper(preg_replace('/\s+/', '', $postcode)));
        if ($postcode === '') {
            throw new \RuntimeException('Postcode is required.', 422);
        }

        $businessAddress = $this->getBusinessAddress();
        if ($businessAddress === '') {
            throw new \RuntimeException('Business address is not configured.', 503);
        }

        // Extract UK postcode from business address using regex
        if (! preg_match('/([A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2})\s*$/i', $businessAddress, $m)) {
            throw new \RuntimeException('Could not extract postcode from business address. Please ensure the address ends with a valid UK postcode.', 503);
        }
        $businessPostcode = strtoupper(trim($m[1]));

        $customerCoords = $this->coordsForPostcode($postcode);
        $businessCoords = $this->coordsForPostcode($businessPostcode);

        $distanceMiles = $this->drivingDistanceMilesOsrm(
            $businessCoords['lat'], $businessCoords['lng'],
            $customerCoords['lat'], $customerCoords['lng']
        );

        return $this->buildQuoteResult($distanceMiles);
    }

    /**
     * @return array{lat: float, lng: float}
     * @throws \RuntimeException
     */
    public function coordsForPostcode(string $postcode): array
    {
        $postcode = strtoupper(preg_replace('/\s+/', '', $postcode));
        $cacheKey = 'postcode_coords:' . $postcode;

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
            return $cached;
        }

        try {
            $response = Http::timeout(10)->get(self::POSTCODES_IO_URL . '/' . urlencode($postcode));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach postcode lookup service.', 502);
        }

        if (! $response->ok()) {
            throw new \RuntimeException('Invalid or unknown postcode.', 422);
        }

        $payload = $response->json();
        $lat = $payload['result']['latitude'] ?? null;
        $lng = $payload['result']['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            throw new \RuntimeException('Could not locate postcode.', 422);
        }

        $coords = ['lat' => (float) $lat, 'lng' => (float) $lng];
        Cache::put($cacheKey, $coords, now()->addDays(30));

        return $coords;
    }

    /**
     * Get driving distance in miles using OSRM (free, no API key required).
     * Falls back to Haversine if OSRM is unavailable.
     */
    public function drivingDistanceMilesOsrm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $cacheKey = 'osrm_dist:' . md5("{$lat1},{$lng1},{$lat2},{$lng2}");
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (float) $cached;
        }

        try {
            // OSRM expects lng,lat order
            $url = self::OSRM_URL . "/{$lng1},{$lat1};{$lng2},{$lat2}?overview=false";
            $response = Http::timeout(10)->get($url);

            if ($response->ok()) {
                $payload = $response->json();
                $meters = $payload['routes'][0]['distance'] ?? null;
                if ($meters !== null && $meters > 0) {
                    $miles = round((float) $meters / self::METERS_PER_MILE, 2);
                    Cache::put($cacheKey, $miles, now()->addDays(7));
                    return $miles;
                }
            }
        } catch (\Throwable) {
            // fall through to Haversine
        }

        // Fallback: straight-line Haversine
        return $this->haversineDistanceMiles($lat1, $lng1, $lat2, $lng2);
    }

    public function haversineDistanceMiles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMiles = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return round($earthRadiusMiles * 2 * asin(sqrt($a)), 2);
    }

    public function getBusinessAddress(): string
    {
        $value = Setting::query()->where('key', 'address')->value('value');

        return trim((string) ($value ?? ''));
    }

    /**
     * @return array{lat: float, lng: float}
     *
     * @throws \RuntimeException
     */
    public function geocodeAddress(string $address, ?string $apiKey = null, string $context = 'address'): array
    {
        $address = trim($address);
        if ($address === '') {
            throw new \RuntimeException("Could not geocode the {$context} address.", 422);
        }

        $apiKey ??= $this->resolveGoogleMapsApiKey();

        $cacheKey = 'delivery_geocode:'.md5(mb_strtolower($address));

        /** @var array{lat: float, lng: float}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)->get(self::GEOCODE_URL, [
                'address' => $address,
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::error('Geocode request failed: '.$e->getMessage());

            throw new \RuntimeException('Could not reach location services.', 502);
        }

        if (! $response->ok()) {
            throw new \RuntimeException('Location lookup failed.', 502);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? '');

        if ($status === 'ZERO_RESULTS' || empty($payload['results'][0]['geometry']['location'])) {
            throw new \RuntimeException("Could not locate the {$context} address.", 422);
        }

        if ($status !== 'OK') {
            Log::warning('Google Geocoding API returned non-OK status.', ['status' => $status, 'context' => $context]);

            throw new \RuntimeException("Could not resolve the {$context} address.", 422);
        }

        $location = $payload['results'][0]['geometry']['location'];
        $coords = [
            'lat' => (float) ($location['lat'] ?? 0),
            'lng' => (float) ($location['lng'] ?? 0),
        ];

        if ($coords['lat'] === 0.0 && $coords['lng'] === 0.0) {
            throw new \RuntimeException("Could not resolve the {$context} address.", 422);
        }

        Cache::put($cacheKey, $coords, now()->addDay());

        return $coords;
    }

    /**
     * @throws \RuntimeException
     */
    public function drivingDistanceMiles(
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng,
        ?string $apiKey = null
    ): float {
        $apiKey ??= $this->resolveGoogleMapsApiKey();

        $origins = "{$originLat},{$originLng}";
        $destinations = "{$destinationLat},{$destinationLng}";

        try {
            $response = Http::timeout(15)->get(self::DISTANCE_MATRIX_URL, [
                'origins' => $origins,
                'destinations' => $destinations,
                'units' => 'imperial',
                'key' => $apiKey,
            ]);
        } catch (\Throwable $e) {
            Log::error('Distance Matrix request failed: '.$e->getMessage());

            throw new \RuntimeException('Could not reach location services.', 502);
        }

        if (! $response->ok()) {
            throw new \RuntimeException('Distance lookup failed.', 502);
        }

        $payload = $response->json();
        $status = (string) ($payload['status'] ?? '');

        if ($status !== 'OK') {
            Log::warning('Google Distance Matrix API returned non-OK status.', ['status' => $status]);

            throw new \RuntimeException('Could not calculate delivery distance.', 422);
        }

        $element = $payload['rows'][0]['elements'][0] ?? null;
        $elementStatus = is_array($element) ? (string) ($element['status'] ?? '') : '';

        if ($elementStatus !== 'OK' || ! is_array($element)) {
            throw new \RuntimeException('Could not calculate delivery distance for this address.', 422);
        }

        $meters = (float) ($element['distance']['value'] ?? 0);
        if ($meters <= 0) {
            throw new \RuntimeException('Could not calculate delivery distance for this address.', 422);
        }

        return round($meters / self::METERS_PER_MILE, 2);
    }

    /**
     * @return array{
     *     distance_miles: float,
     *     delivery_charge: float,
     *     matched_tier: array{from: float, to: float, charge: float}|null,
     *     out_of_range: bool
     * }
     */
    public function buildQuoteResult(float $distanceMiles): array
    {
        $tier = $this->resolveChargeTier($distanceMiles);

        if ($tier === null) {
            return [
                'distance_miles' => $distanceMiles,
                'delivery_charge' => 0.0,
                'matched_tier' => null,
                'out_of_range' => true,
            ];
        }

        return [
            'distance_miles' => $distanceMiles,
            'delivery_charge' => round((float) $tier->charge, 2),
            'matched_tier' => [
                'from' => (float) $tier->from_distance,
                'to' => (float) $tier->to_distance,
                'charge' => round((float) $tier->charge, 2),
            ],
            'out_of_range' => false,
        ];
    }

    public function resolveCharge(float $miles): float
    {
        $tier = $this->resolveChargeTier($miles);

        return $tier !== null ? round((float) $tier->charge, 2) : 0.0;
    }

    private function resolveChargeTier(float $miles): ?DeliveryCharge
    {
        return DeliveryCharge::query()
            ->where('status', 'active')
            ->where('from_distance', '<=', $miles)
            ->where('to_distance', '>', $miles)
            ->orderBy('from_distance')
            ->first();
    }

    /**
     * @throws \RuntimeException
     */
    private function resolveGoogleMapsApiKey(): string
    {
        $setting = ApiSetting::query()->where('key_name', 'google_maps')->first();
        if (! $setting || ! $setting->is_enabled) {
            throw new \RuntimeException('Location services are not configured.', 503);
        }

        $apiKey = trim((string) $setting->value);
        if ($apiKey === '') {
            throw new \RuntimeException('Location services are not configured.', 503);
        }

        return $apiKey;
    }

    private function composeAddressLine(string $address, string $city, string $postcode): string
    {
        return trim(implode(', ', array_filter([
            trim($address),
            trim($city),
            trim($postcode),
            'United Kingdom',
        ])));
    }
}
