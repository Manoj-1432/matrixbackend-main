<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminAuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $credentials = $validator->validated();

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $credentials['email'])
            ->with('role')
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $this->jsonError('Invalid credentials.', null, 401);
        }

        if (! $user->hasMinimumRole(Role::ADMIN)) {
            return $this->jsonError('You are not authorized to access the admin API.', null, 403);
        }

        if (! $user->is_active) {
            return $this->jsonError('Account suspended.', null, 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('admin-api')->plainTextToken;

        return $this->jsonSuccess([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return $this->jsonSuccess(null, 'Logged out.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $user->loadMissing('role');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
            ] : null,
            'permissions' => $user->isSuperAdmin() 
                ? ['dashboard', 'customers', 'vehicles', 'orders', 'payments', 'tyres', 'settings', 'test_dvla', 'api_settings', 'update']
                : ($user->permissions ?? []),
        ];
    }
}
