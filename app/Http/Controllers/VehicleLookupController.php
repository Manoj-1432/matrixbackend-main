<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Setting;
use App\Models\Tyre;
use App\Services\DvlaTyreLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VehicleLookupController extends Controller
{
    use ApiResponse;

    public function lookup(Request $request, DvlaTyreLookupService $service): JsonResponse
    {
        $rawTyreSize = $request->input('tyre_size');
        $tyreSize = trim(is_string($rawTyreSize) ? $rawTyreSize : '');
        $rawSpeedRating = $request->input('speed_rating');
        $speedRating = strtoupper(trim(is_string($rawSpeedRating) ? $rawSpeedRating : ''));
        if ($tyreSize !== '') {
            $validator = Validator::make(
                ['tyre_size' => $tyreSize, 'speed_rating' => $speedRating],
                [
                    'tyre_size' => ['required', 'string', 'min:3', 'max:32'],
                    'speed_rating' => ['nullable', 'string', 'max:10'],
                ]
            );

            if ($validator->fails()) {
                return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
            }

            $normalizedTyreSize = strtoupper(preg_replace('/\s+/', '', $tyreSize));
            $matchedTyres = Tyre::query()
                ->with(['brand', 'size', 'season', 'speedRating'])
                ->where('status', true)
                ->whereHas('size', function ($query) use ($normalizedTyreSize) {
                    $query->whereRaw('UPPER(REPLACE(label, " ", "")) = ?', [$normalizedTyreSize]);
                })
                ->when($speedRating !== '', function ($query) use ($speedRating) {
                    $query->whereHas('speedRating', function ($speedQuery) use ($speedRating) {
                        $speedQuery->whereRaw('UPPER(rating) = ?', [$speedRating]);
                    });
                })
                ->limit(24)
                ->get();

            return $this->jsonSuccess([
                'vehicle' => [
                    'make' => 'Tyre Size Search',
                    'model' => $tyreSize,
                    'yearOfManufacture' => now()->year,
                ],
                'tyres' => $matchedTyres,
                'tyre' => [
                    'likely_sizes' => [$tyreSize],
                ],
                'search_type' => 'tyre_size',
                'search_value' => $tyreSize,
                'speed_rating' => $speedRating,
                'contact_number' => $this->getContactNumber(),
            ]);
        }

        $raw = $request->input('registration_number');
        $vrm = strtoupper(preg_replace('/\s+/', '', is_string($raw) ? trim($raw) : ''));

        $validator = Validator::make(
            ['registration_number' => $vrm],
            [
                'registration_number' => ['required', 'string', 'min:2', 'max:16'],
            ]
        );

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $result = $service->lookupByVrm($vrm);

        if ($result['dvla_error'] !== null && ! $result['dvla_success']) {
            return $this->jsonError($result['dvla_error'], $result, 400);
        }

        $likelySizes = $this->extractLikelySizes($result);
        if ($likelySizes !== []) {
            $normalizedLikelySizes = array_map(
                static fn (string $size): string => strtoupper(preg_replace('/\s+/', '', $size)),
                $likelySizes
            );
            $normalizedLikelySizes = array_values(array_unique($normalizedLikelySizes));

            $matchedTyres = Tyre::query()
                ->with(['brand', 'size', 'season'])
                ->where('status', true)
                ->whereHas('size', function ($query) use ($normalizedLikelySizes) {
                    $query->where(function ($sizeQuery) use ($normalizedLikelySizes) {
                        foreach ($normalizedLikelySizes as $index => $normalizedSize) {
                            if ($index === 0) {
                                $sizeQuery->whereRaw('UPPER(REPLACE(label, " ", "")) = ?', [$normalizedSize]);
                            } else {
                                $sizeQuery->orWhereRaw('UPPER(REPLACE(label, " ", "")) = ?', [$normalizedSize]);
                            }
                        }
                    });
                })
                ->limit(24)
                ->get();

            $result['tyres'] = $matchedTyres;
        } else {
            $result['tyres'] = [];
        }

        $result['contact_number'] = $this->getContactNumber();

        return $this->jsonSuccess($result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function extractLikelySizes(array $result): array
    {
        $tyrePayload = $result['tyre'] ?? null;
        if (! is_array($tyrePayload)) {
            return [];
        }

        $rawLikelySizes = $tyrePayload['likely_sizes'] ?? null;
        if (! is_array($rawLikelySizes)) {
            return [];
        }

        $likelySizes = [];
        foreach ($rawLikelySizes as $size) {
            if (! is_string($size)) {
                continue;
            }

            $trimmed = trim($size);
            if ($trimmed !== '') {
                $likelySizes[] = $trimmed;
            }
        }

        return $likelySizes;
    }

    private function getContactNumber(): string
    {
        $value = Setting::query()
            ->where('key', 'contact_number')
            ->value('value');

        return is_string($value) ? trim($value) : '';
    }
}
