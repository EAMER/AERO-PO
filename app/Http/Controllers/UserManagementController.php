<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class UserManagementController extends Controller
{
    public function __construct(private UserManagementService $users) {}

    public function index()
    {
        $this->authorizeAdmin();

        return view('users.index', ['users' => User::orderBy('name')->get()]);
    }

    public function create()
    {
        $this->authorizeAdmin();

        return view('users.create', ['roles' => $this->assignableRoles()]);
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'role' => 'required|string',
        ]);

        $role = Role::tryFrom($data['role']);
        abort_unless($role, 422, 'Unknown role.');

        try {
            $result = $this->users->create($data['name'], $data['email'], $role);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with([
            'status' => 'User created.',
            'temporary_password' => $result['temporary_password'],
            'new_user_email' => $result['user']->email,
        ]);
    }

    public function updateRole(User $user, Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate(['role' => 'required|string']);
        $role = Role::tryFrom($data['role']);
        abort_unless($role, 422, 'Unknown role.');

        try {
            $this->users->updateRole($user, $role, Auth::user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('status', 'Role updated.');
    }

    public function setActive(User $user, Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate(['active' => 'required|boolean']);

        try {
            $this->users->setActive($user, (bool) $data['active'], Auth::user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['active' => $e->getMessage()]);
        }

        return back()->with('status', $data['active'] ? 'User reactivated.' : 'User deactivated.');
    }

    public function resetPassword(User $user)
    {
        $this->authorizeAdmin();

        $password = $this->users->resetPassword($user);

        return back()->with([
            'status' => 'Password reset.',
            'temporary_password' => $password,
            'new_user_email' => $user->email,
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(Auth::user()->role->isAdmin(), 403);
    }

    /** @return list<Role> every role except super admin, which isn't managed per-tenant. */
    private function assignableRoles(): array
    {
        return array_filter(Role::cases(), fn (Role $r) => $r !== Role::SuperAdmin);
    }
}
