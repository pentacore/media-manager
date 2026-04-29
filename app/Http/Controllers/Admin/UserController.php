<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Mail\UserInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'users' => User::query()
                ->with(['embyUserLinks:id,user_id,emby_username'])
                ->orderBy('name')
                ->get()
                ->map(function (User $user): array {
                    $link = $user->embyUserLinks->first();

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'sso_provider' => $user->sso_provider,
                        'avatar_url' => $user->avatar_url,
                        'created_at' => $user->created_at->diffForHumans(),
                        'emby_link_id' => $link?->id,
                        'emby_username' => $link?->emby_username,
                    ];
                }),
            'roles' => UserRole::mapForSelect(labelKey: 'label'),
        ]);
    }

    public function store(CreateUserRequest $createUserRequest): RedirectResponse
    {
        $fields = ['name', 'email', 'role'];

        if ($createUserRequest->boolean('set_password')) {
            $fields[] = 'password';
        }

        $user = User::create($createUserRequest->safe()->only($fields));
        $user->forceFill(['email_verified_at' => now()])->save();

        if (! $createUserRequest->boolean('set_password')) {
            $inviteUrl = URL::temporarySignedRoute(
                'auth.invite.accept',
                now()->addHours(48),
                ['user' => $user->id],
            );

            Mail::to($user)->send(new UserInvitation($user, $inviteUrl));

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $user->email])]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);
        }

        return to_route('admin.users.index');
    }

    public function updateRole(UpdateUserRoleRequest $updateUserRoleRequest, User $user): RedirectResponse
    {
        abort_if($user->id === $updateUserRoleRequest->user()->id, 403);

        $user->update(['role' => $updateUserRoleRequest->validated('role')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User role updated.')]);

        return to_route('admin.users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->id === request()->user()->id, 403);

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }
}
