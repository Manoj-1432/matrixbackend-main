<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehiclesController extends Controller
{
    /**
     * List vehicles with optional search/status filter + aggregate stats.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::with('user:id,name,email,phone')
            ->orderBy('created_at', 'desc');

        // Search by registration, make, model, or owner name/email
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('registration', 'like', "%{$search}%")
                  ->orWhere('make', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($q2) use ($search) {
                      $q2->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage   = min((int) ($request->query('per_page', 25)), 100);
        $paginated = $query->paginate($perPage);

        // Aggregate stats (DB-wide, unfiltered)
        $stats = [
            'total'    => Vehicle::count(),
            'active'   => Vehicle::where('status', 'active')->count(),
            'inactive' => Vehicle::where('status', 'inactive')->count(),
            'pending'  => Vehicle::where('status', 'pending')->count(),
        ];

        return response()->json([
            'data' => [
                'vehicles' => $paginated->items(),
                'meta'     => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                ],
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Create a new vehicle.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'      => 'nullable|exists:users,id',
            'registration' => 'required|string|max:20|unique:vehicles,registration',
            'make'         => 'nullable|string|max:100',
            'model'        => 'nullable|string|max:100',
            'year'         => 'nullable|integer|min:1900|max:2100',
            'status'       => ['nullable', Rule::in(['active', 'inactive', 'pending'])],
            'notes'        => 'nullable|string|max:2000',
        ]);

        $vehicle = Vehicle::create($validated);
        $vehicle->load('user:id,name,email,phone');

        return response()->json(['data' => $vehicle], 201);
    }

    /**
     * Update an existing vehicle.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        $validated = $request->validate([
            'user_id'      => 'nullable|exists:users,id',
            'registration' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('vehicles', 'registration')->ignore($vehicle->id),
            ],
            'make'   => 'nullable|string|max:100',
            'model'  => 'nullable|string|max:100',
            'year'   => 'nullable|integer|min:1900|max:2100',
            'status' => ['nullable', Rule::in(['active', 'inactive', 'pending'])],
            'notes'  => 'nullable|string|max:2000',
        ]);

        $vehicle->update($validated);
        $vehicle->load('user:id,name,email,phone');

        return response()->json(['data' => $vehicle]);
    }

    /**
     * Delete a vehicle.
     */
    public function destroy(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);
        $vehicle->delete();

        return response()->json(['message' => 'Vehicle deleted successfully.']);
    }
}
