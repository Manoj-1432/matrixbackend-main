<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\TyreType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PublicTyreTypeController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $types = TyreType::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'status'])
            ->map(function (TyreType $type): array {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'slug' => Str::slug($type->name),
                    'description' => $type->description,
                ];
            })
            ->values();

        return $this->jsonSuccess(['tyre_types' => $types]);
    }
}

