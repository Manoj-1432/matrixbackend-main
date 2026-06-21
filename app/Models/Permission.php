<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    public const MANAGE_API_SETTINGS = 'manage_api_settings';

    public const MANAGE_USERS = 'manage_users';

    public const ASSIGN_ROLES = 'assign_roles';

    public const VIEW_USERS = 'view_users';

    public const BASIC_APP = 'basic_app';

    protected $fillable = [
        'name',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
