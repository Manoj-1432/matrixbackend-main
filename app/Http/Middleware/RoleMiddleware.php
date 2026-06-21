<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'status' => false,
                'data' => null,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($role === Role::SUPER_ADMIN) {
            if (! $user->isSuperAdmin()) {
                return $this->forbidden();
            }
        } elseif ($role === Role::ADMIN) {
            if (! $user->hasMinimumRole(Role::ADMIN)) {
                return $this->forbidden();
            }
        } elseif ($user->role?->name !== $role) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): Response
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'message' => 'You do not have permission to perform this action.',
        ], 403);
    }
}
