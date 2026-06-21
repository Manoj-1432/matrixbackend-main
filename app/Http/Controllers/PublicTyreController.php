<?php

namespace App\Http\Controllers;

use App\Models\Tyre;
use Illuminate\Http\Request;

class PublicTyreController extends Controller
{
    public function index(Request $request)
    {
        $query = Tyre::query()
            ->with(['brand', 'size', 'season', 'tyreType', 'fuelEfficiency', 'speedRating'])
            ->where('status', true);

        // Optional filtering by size, brand, etc., can be added here if needed
        if ($request->has('size')) {
            $query->whereHas('size', function ($q) use ($request) {
                $q->where('label', $request->input('size'));
            });
        }

        if ($request->has('brand')) {
            $query->whereHas('brand', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('brand') . '%');
            });
        }

        if ($request->has('brand_slug')) {
            $slug = (string) $request->input('brand_slug');
            $guess = trim(str_replace('-', ' ', $slug));
            if ($guess !== '') {
                $query->whereHas('brand', function ($q) use ($guess) {
                    $q->where('name', 'like', '%' . $guess . '%');
                });
            }
        }

        if ($request->has('tyre_type')) {
            $tyreType = trim((string) $request->input('tyre_type'));
            if ($tyreType !== '') {
                $query->whereHas('tyreType', function ($q) use ($tyreType) {
                    $q->where('name', 'like', '%' . $tyreType . '%')
                        ->where('status', 'active');
                });
            }
        }

        // Return paginated response
        return response()->json([
            'data' => $query->paginate(24) // 24 tyres per page (multiple of 3/4 grids)
        ]);
    }

    public function show($id)
    {
        $tyre = Tyre::with(['brand', 'size', 'season', 'tyreType', 'fuelEfficiency', 'speedRating'])
            ->where('status', true)
            ->findOrFail($id);

        return response()->json(['data' => $tyre]);
    }
}
