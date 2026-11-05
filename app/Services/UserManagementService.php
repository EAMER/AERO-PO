<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Support\ProcurementAccess as Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UserManagementService
{
    public function create(string $name, string $email, Role $role): array
    {
        $actor = auth()->user();
        abort_unless($actor, 403);
        Access::roles($actor);
        if ($role === Role::SuperAdmin) {
            throw new InvalidArgumentException('Super admin accounts are not created through this screen.');
        }

        return DB::transaction(function () use ($name, $email, $role, $actor) {
            $password = Str::password(16);
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role]);
            Access::audit($user, $actor, 'user.created', [], $user->only(['name', 'email', 'role', 'is_active']));

            return ['user' => $user, 'temporary_password' => $password];
        });
    }

    public function updateRole(User $user, Role $role, User $actor): User
    {
        Access::roles($actor);
        Access::tenant($user);
        if ($role === Role::SuperAdmin || $user->role === Role::SuperAdmin || ($user->id === $actor->id && $role !== $actor->role)) {
            throw new InvalidArgumentException('You cannot change your own role or manage super admin accounts here.');
        }

        return DB::transaction(function () use ($user, $role, $actor) {
            $before = ['role' => $user->role->value];
            $user->update(['role' => $role]);
            Access::audit($user, $actor, 'user.role_changed', $before, ['role' => $role->value]);

            return $user->fresh();
        });
    }

    public function setActive(User $user, bool $active, User $actor): User
    {
        Access::roles($actor);
        Access::tenant($user);
        if ($user->role === Role::SuperAdmin || ($user->id === $actor->id && ! $active)) {
            throw new InvalidArgumentException('You cannot deactivate your own account or manage super admin accounts here.');
        }

        return DB::transaction(function () use ($user, $active, $actor) {
            $before = ['is_active' => $user->is_active];
            $user->update(['is_active' => $active, 'remember_token' => Str::random(60)]);
            if (! $active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Access::audit($user, $actor, 'user.activation_changed', $before, ['is_active' => $active]);

            return $user->fresh();
        });
    }

    public function resetPassword(User $user): string
    {
        $actor = auth()->user();
        abort_unless($actor, 403);
        Access::roles($actor);
        Access::tenant($user);
        if ($user->role === Role::SuperAdmin) {
            throw new InvalidArgumentException('Super admin accounts cannot be managed here.');
        }

        return DB::transaction(function () use ($user, $actor) {
            $password = Str::password(16);
            $user->update(['password' => $password, 'remember_token' => Str::random(60)]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Access::audit($user, $actor, 'user.password_reset');

            return $password;
        });
    }
}
