<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Users</h2>
            <a href="{{ route('users.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                + Add user
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            @if (session('temporary_password'))
                <div class="rounded-md bg-yellow-50 border border-yellow-300 px-4 py-3 text-sm text-yellow-900">
                    <p class="font-semibold">Temporary password for {{ session('new_user_email') }}:</p>
                    <p class="font-mono text-base mt-1">{{ session('temporary_password') }}</p>
                    <p class="mt-1 text-xs text-yellow-700">This is shown once. Share it with them directly, then have them change it on first login.</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    @foreach ($errors->all() as $error) <p>{{ $error }}</p> @endforeach
                </div>
            @endif

            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs text-gray-500 uppercase">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($users as $u)
                            <tr>
                                <td class="px-4 py-3">{{ $u->name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $u->email }}</td>
                                <td class="px-4 py-3">
                                    @if ($u->role->value === 'super_admin')
                                        <span class="text-gray-400">Super admin</span>
                                    @else
                                        <form method="POST" action="{{ route('users.update-role', $u) }}" class="inline-flex">
                                            @csrf @method('PATCH')
                                            <select name="role" onchange="this.form.submit()" class="rounded-md border-gray-300 text-xs shadow-sm">
                                                @foreach ($roles ?? \App\Enums\Role::cases() as $r)
                                                    @continue($r->value === 'super_admin')
                                                    <option value="{{ $r->value }}" @selected($u->role === $r)>{{ ucwords(str_replace('_', ' ', $r->value)) }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($u->is_active)
                                        <span class="text-green-600 text-xs">Active</span>
                                    @else
                                        <span class="text-red-500 text-xs">Deactivated</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right space-x-2">
                                    <form method="POST" action="{{ route('users.reset-password', $u) }}" class="inline">
                                        @csrf
                                        <button class="text-xs text-indigo-600 hover:underline">Reset password</button>
                                    </form>
                                    @if ($u->role->value !== 'super_admin')
                                        <form method="POST" action="{{ route('users.set-active', $u) }}" class="inline">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="active" value="{{ $u->is_active ? '0' : '1' }}">
                                            <button class="text-xs {{ $u->is_active ? 'text-red-500' : 'text-green-600' }} hover:underline">
                                                {{ $u->is_active ? 'Deactivate' : 'Reactivate' }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
