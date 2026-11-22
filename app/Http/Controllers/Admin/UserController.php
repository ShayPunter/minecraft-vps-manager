<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Users. Sign-up is off, so this is where every account is made.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();

        $users = User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'can_delete' => $actor->can('delete', $user),
            ]);

        return Inertia::render('admin/users/Index', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/Create', [
            'roles' => Role::options(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, role: string, password: string} $data */
        $data = $request->validated();

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->role = Role::from($data['role']);
        // Admins vouch for the address; there's no sign-up mail to confirm it.
        $user->email_verified_at = now();
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Created {$user->name}."]);

        return to_route('admin.users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('admin/users/Edit', [
            'editing' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_self' => $request->user()?->is($user) ?? false,
            ],
            'roles' => Role::options(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        /** @var array{name: string, email: string, role: string, password: string|null} $data */
        $data = $request->validated();

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = Role::from($data['role']);

        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved {$user->name}."]);

        return to_route('admin.users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $name = $user->name;
        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Deleted {$name}."]);

        return to_route('admin.users.index');
    }
}
