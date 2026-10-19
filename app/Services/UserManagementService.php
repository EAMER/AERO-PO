<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Tenant admins create and manage the real people who use the system - the seeded test accounts are for dev only. */
class UserManagementService
{
    public function create(string $name, string $email, Role $role): array
    {
        if ($role === Role::SuperAdmin) {
            throw new InvalidArgumentException('Super admin accounts are not created through this screen.');
        }

        $password = Str::password(16);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // hashed cast handles this
            'role' => $role,
        ]);

        // Returned once, to hand to the person out of band - never stored or logged in the clear.
        return ['user' => $user, 'temporary_password' => $password];
    }

    public function updateRole(User $user, Role $role, User $actor): User
    {
        if ($role === Role::SuperAdmin) {
            throw new InvalidArgumentException('Super admin accounts are not assigned through this screen.');
        }
        if ($user->id === $actor->id && $role !== $actor->role && $actor->role === Role::TenantAdmin) {
            throw new InvalidArgumentException('You cannot change your own role.');
        }

        $user->update(['role' => $role]);

        return $user->fresh();
    }

    public function setActive(User $user, bool $active, User $actor): User
    {
        if ($user->id === $actor->id && ! $active) {
            throw new InvalidArgumentException('You cannot deactivate your own account.');
        }

        $user->update(['is_active' => $active]);

        return $user->fresh();
    }

    public function resetPassword(User $user): string
    {
        $password = Str::password(16);
        $user->update(['password' => $password]);

        return $password;
    }
}
