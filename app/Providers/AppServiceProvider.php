<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('manage-api-settings', function (?User $user): bool {
            return $user?->hasPermission(Permission::MANAGE_API_SETTINGS) ?? false;
        });

        Gate::define('manage-users', function (?User $user): bool {
            return $user?->hasPermission(Permission::MANAGE_USERS) ?? false;
        });

        Gate::define('assign-roles', function (?User $user): bool {
            return $user?->hasPermission(Permission::ASSIGN_ROLES) ?? false;
        });
    }
}
