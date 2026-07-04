<?php

namespace App\Services;

use App\Models\ApiSetting;

class DvlaTyreLookupService
{
    private const DVLA_URL = 'https://driver-vehicle-licensing.api.gov.uk/vehicle-enquiry/v1/vehicles';

    /**
     * @return array{
     *     dvla_success: bool,
     *     dvla_http_code: int,
     *     dvla_error: string|null,
     *     vehicle: array<string, mixed>|null,
     *     tyre: array<string, mixed>|null,
     *     tyre_error: array<string, mixed>|null
     * }
     */
    public function lookupByVrm(string $vrm): array
    {
        $vrm = strtoupper(preg_replace('/\s+/', '', trim($vrm)));

        $empty = [
            'dvla_success' => false,
            'dvla_http_code' => 0,
            'dvla_error' => 'Enter a valid registration (VRM).',
            'vehicle' => null,
            'tyre' => null,
            'tyre_error' => null,
        ];

        if ($vrm === '') {
            return $empty;
        }

        $dvlaSetting = ApiSetting::query()->where('key_name', 'dvla')->first();
        if (! $dvlaSetting || trim((string) $dvlaSetting->value) === '') {
            return [
                'dvla_success' => false,
                'dvla_http_code' => 0,
                'dvla_error' => 'DVLA API key is not configured. Add it under API Settings.',
                'vehicle' => null,
                'tyre' => null,
                'tyre_error' => null,
            ];
        }

        if (! $dvlaSetting->is_enabled) {
            return [
                'dvla_success' => false,
                'dvla_http_code' => 0,
                'dvla_error' => 'DVLA integration is disabled in API Settings.',
                'vehicle' => null,
                'tyre' => null,
                'tyre_error' => null,
            ];
        }

        $dvlaKey = $dvlaSetting->value;
        $dvlaResult = $this->callDvla($dvlaKey, $vrm);

        if (! $dvlaResult['ok']) {
            return [
                'dvla_success' => false,
                'dvla_http_code' => $dvlaResult['http_code'],
                'dvla_error' => $dvlaResult['error'],
                'vehicle' => is_array($dvlaResult['body']) ? $dvlaResult['body'] : null,
                'tyre' => null,
                'tyre_error' => null,
            ];
        }

        $vehicle = $dvlaResult['body'];
        if (! is_array($vehicle)) {
            return [
                'dvla_success' => false,
                'dvla_http_code' => $dvlaResult['http_code'],
                'dvla_error' => 'DVLA response was not valid JSON.',
                'vehicle' => null,
                'tyre' => null,
                'tyre_error' => null,
            ];
        }
        $vehicle = $this->normalizeVehiclePayload($vehicle);

        $openaiSetting = ApiSetting::query()->where('key_name', 'openai')->first();
        $tyre = null;
        $tyreError = null;

        $hasKey = $openaiSetting && trim((string) $openaiSetting->value) !== '';

        if ($hasKey && $openaiSetting->is_enabled) {
            $tyreCall = $this->callOpenAiForTyres($openaiSetting->value, $vehicle);
            if ($tyreCall['ok']) {
                $tyre = $tyreCall['data'];
            } else {
                $tyreError = $tyreCall;
            }
        } else {
            $message = ! $openaiSetting || ! $hasKey
                ? 'Add a Workatmo Tyre API key under API Settings (Workatmo Tyre Api card), then save.'
                : 'Turn on the Workatmo Tyre Api toggle in API Settings to enable tyre recommendations.';

            $tyreError = [
                'skipped' => true,
                'error' => $message,
            ];
        }

        return [
            'dvla_success' => true,
            'dvla_http_code' => $dvlaResult['http_code'],
            'dvla_error' => null,
            'vehicle' => $vehicle,
            'tyre' => $tyre,
            'tyre_error' => $tyreError,
        ];
    }

    /**
     * @return array{ok: bool, http_code: int, error?: string, body?: mixed}
     */
    private function callDvla(string $apiKey, string $registrationNumber): array
    {
        $payload = json_encode(['registrationNumber' => $registrationNumber], JSON_THROW_ON_ERROR);

        $ch = curl_init(self::DVLA_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'x-api-key: '.$apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'http_code' => 500, 'error' => 'DVLA request failed: '.$curlError];
        }

        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'error' => 'DVLA request rejected (HTTP '.$httpCode.'). Check your API key in Admin → API Settings.',
                'body' => $decoded ?? $response,
            ];
        }

        return ['ok' => true, 'http_code' => $httpCode, 'body' => $decoded];
    }

    /**
     * Ensure commonly-used frontend fields are present even when provider
     * payload naming differs across sources/environments.
     *
     * @param  array<string, mixed>  $vehicle
     * @return array<string, mixed>
     */
    private function normalizeVehiclePayload(array $vehicle): array
    {
        // DVLA commonly omits `model`; some integrations expose adjacent keys.
        if (! isset($vehicle['model']) || trim((string) $vehicle['model']) === '') {
            $candidates = [
                $vehicle['vehicleModel'] ?? null,
                $vehicle['modelVariant'] ?? null,
                $vehicle['variant'] ?? null,
                $vehicle['derivative'] ?? null,
            ];

            foreach ($candidates as $candidate) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    $vehicle['model'] = $value;
                    break;
                }
            }
        }

        return $vehicle;
    }

    /**
     * @param  array<string, mixed>  $vehicleData
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string, httpCode?: int, details?: mixed, raw?: string}
     */
    private function callOpenAiForTyres(string $openAiKey, array $vehicleData): array
    {
        $make            = strtoupper(trim((string) ($vehicleData['make'] ?? '')));
        $model           = trim((string) ($vehicleData['model'] ?? ''));
        $year            = (int) ($vehicleData['yearOfManufacture'] ?? 0);
        $fuelType        = strtoupper(trim((string) ($vehicleData['fuelType'] ?? '')));
        $engineCc        = (int) ($vehicleData['engineCapacity'] ?? 0);
        $colour          = trim((string) ($vehicleData['colour'] ?? ''));

        $vehicleDesc = "{$make}";
        if ($model !== '') $vehicleDesc .= " {$model}";
        if ($year > 0)     $vehicleDesc .= ", year {$year}";
        if ($fuelType !== '') $vehicleDesc .= ", fuel: {$fuelType}";
        if ($engineCc > 0) $vehicleDesc .= ", engine: {$engineCc}cc";

        $prompt = "Vehicle details from DVLA: {$vehicleDesc}.\n\n"
            ."Your task:\n"
            ."1. If the model name is missing or vague, use the make + year + engine capacity (cc) + fuel type to identify the most likely specific model (e.g. Vauxhall 1248cc diesel 2014 → Corsa 1.3 CDTi).\n"
            ."2. Return the OEM (factory-fitted) tyre sizes for that specific model and year. Check the owner's manual specification, not aftermarket options.\n"
            ."3. If multiple trim levels exist with different sizes, list only the 1-2 most common sizes.\n"
            ."4. Format sizes exactly as: 205/55 R16 (width/profile SPACE R rim) — no extra characters.\n\n"
            ."Return ONLY strict JSON with these keys:\n"
            ."- likely_sizes: array of strings (OEM sizes, max 2)\n"
            ."- notes: array of strings (e.g. model identified, any uncertainty)\n"
            ."- recommended_pressure_psi_front: number\n"
            ."- recommended_pressure_psi_rear: number";

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert automotive tyre specialist with deep knowledge of OEM tyre fitments for UK vehicles. Always reply with valid JSON only, no markdown, no explanation.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$openAiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 25,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Workatmo Tyre API request failed: '.$curlError];
        }

        $decoded = json_decode($raw, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = 'Workatmo Tyre API request failed';
            if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) && isset($decoded['error']['message'])) {
                $providerMessage = trim((string) $decoded['error']['message']);
                if ($providerMessage !== '') {
                    $message = $providerMessage;
                }
            }
            return [
                'ok' => false,
                'error' => $message,
                'httpCode' => $httpCode,
                'details' => $decoded,
            ];
        }

        $content = $decoded['choices'][0]['message']['content'] ?? '';
        $cleanContent = trim((string) $content);
        if (str_starts_with($cleanContent, '```')) {
            $cleanContent = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $cleanContent);
            $cleanContent = preg_replace('/\s*```$/', '', (string) $cleanContent);
            $cleanContent = trim($cleanContent);
        }

        $tyreJson = json_decode($cleanContent, true);
        if (! is_array($tyreJson)) {
            return [
                'ok' => false,
                'error' => 'Workatmo Tyre API response was not valid JSON.',
                'raw' => $content,
            ];
        }

        return ['ok' => true, 'data' => $tyreJson];
    }
}
