<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributesController extends Controller
{
    use ApiResponse;

    private const SUPPORTED_TYPES = [
        'brand',
        'size',
        'season',
        'tyre-type',
        'fuel-efficiency',
        'speed-rating',
    ];

    public function index(Request $request, string $type): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to view attributes.', null, 403);
        }

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            return $this->jsonError('Attribute type not found.', null, 404);
        }

        return $this->jsonSuccess([
            'type' => $type,
            'items' => [],
        ], 'Attribute endpoint ready.');
    }
}
