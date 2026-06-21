<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PublicBrandController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $brands = Brand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'logo_url', 'is_active'])
            ->map(function (Brand $brand): array {
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => Str::slug($brand->name),
                    'logo_url' => $brand->logo_url,
                ];
            })
            ->values();

        return $this->jsonSuccess(['brands' => $brands]);
    }
}

