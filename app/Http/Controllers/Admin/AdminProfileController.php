<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProfileController extends Controller
{
    use ApiResponse;

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->jsonError('Unauthenticated.', null, 401);
        }

        $user->loadMissing('role');

        return $this->jsonSuccess([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
            ] : null,
            'permissions' => $user->isSuperAdmin() 
                ? ['dashboard', 'customers', 'vehicles', 'orders', 'payments', 'tyres', 'attributes', 'settings', 'test_dvla', 'api_settings', 'update']
                : ($user->permissions ?? []),
        ]);
    }
}
