<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateAdminUserCommand extends Command
{
    protected $signature = 'admin:create-user
                            {email : Admin user email}
                            {--name= : Display name (default: derived from email)}
                            {--role=super_admin : Role name: super_admin, admin, or user}
                            {--password= : Plain password (omit to be prompted securely)}';

    protected $description = 'Create or update an admin API user in the database (no default accounts).';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $roleName = (string) $this->option('role');
        $name = $this->option('name') ? (string) $this->option('name') : explode('@', $email, 2)[0];

        $v = Validator::make(
            ['email' => $email, 'role' => $roleName],
            [
                'email' => ['required', 'email'],
                'role' => ['required', 'string', Rule::in([
                    Role::SUPER_ADMIN,
                    Role::ADMIN,
                    Role::USER,
                ])],
            ],
        );
        if ($v->fails()) {
            foreach ($v->errors()->all() as $msg) {
                $this->error($msg);
            }

            return self::FAILURE;
        }

        $roleId = Role::query()->where('name', $roleName)->value('id');
        if (! $roleId) {
            $this->error('Role "'.$roleName.'" not found. Run: php artisan db:seed --class=RbacSeeder');

            return self::FAILURE;
        }

        $password = $this->option('password');
        if (! is_string($password) || $password === '') {
            $password = $this->secret('Password');
        }
        if (! is_string($password) || $password === '') {
            $this->error('Password is required.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role_id' => $roleId,
            ],
        );

        $user->roles()->sync([$roleId]);

        $this->info('Saved user '.$user->email.' with role '.$roleName.' (id '.$user->id.').');

        return self::SUCCESS;
    }
}
