<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            Role::SUPER_ADMIN => Role::query()->firstOrCreate(['name' => Role::SUPER_ADMIN]),
            Role::ADMIN => Role::query()->firstOrCreate(['name' => Role::ADMIN]),
            Role::USER => Role::query()->firstOrCreate(['name' => Role::USER]),
        ];

        $permissions = collect([
            Permission::MANAGE_API_SETTINGS,
            Permission::MANAGE_USERS,
            Permission::ASSIGN_ROLES,
            Permission::VIEW_USERS,
            Permission::BASIC_APP,
        ])->mapWithKeys(fn (string $name) => [
            $name => Permission::query()->firstOrCreate(['name' => $name]),
        ]);

        $roles[Role::SUPER_ADMIN]->permissions()->sync($permissions->pluck('id')->all());

        $roles[Role::ADMIN]->permissions()->sync(
            $permissions
                ->except([Permission::MANAGE_API_SETTINGS])
                ->pluck('id')
                ->all()
        );

        $roles[Role::USER]->permissions()->sync([
            $permissions[Permission::BASIC_APP]->id,
        ]);

        $this->command?->info(
            'RBAC seeded. Create admin users in the database: php artisan admin:create-user your@email.com'
        );
    }
}
