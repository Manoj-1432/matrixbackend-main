<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Size;
use App\Models\SpeedRating;
use Illuminate\Http\JsonResponse;

class PublicTyreSearchOptionsController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $sizes = Size::query()->orderBy('width')->orderBy('profile')->orderBy('rim')->get(['width', 'profile', 'rim']);
        $widths = $sizes->pluck('width')->unique()->values()->map(fn ($value) => (string) $value)->all();
        $ratios = $sizes->pluck('profile')->unique()->values()->map(fn ($value) => (string) $value)->all();
        $rims = $sizes->pluck('rim')->unique()->values()->map(fn ($value) => (string) $value)->all();

        $speedRatings = SpeedRating::query()
            ->where('status', 'active')
            ->orderBy('rating')
            ->pluck('rating')
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        return $this->jsonSuccess([
            'widths' => $widths,
            'ratios' => $ratios,
            'rims' => $rims,
            'speed_ratings' => $speedRatings,
            // No dedicated load index table yet; keep empty until modeled in DB.
            'load_indexes' => [],
        ]);
    }
}
