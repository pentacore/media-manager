<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\LastAdminException;
use App\Actions\ModifyUserAccess;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Mail\UserInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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

    public function store(CreateUserRequest $createUserRequest, AuditLogger $auditLogger): RedirectResponse
    {
        $fields = ['name', 'email', 'role'];
        $sendInvite = ! $createUserRequest->boolean('set_password');

        if (! $sendInvite) {
            $fields[] = 'password';
        }

        // The account and its audit row commit together, before any email:
        // a mail failure can no longer leave an account nobody audited.
        $user = DB::transaction(function () use ($createUserRequest, $fields, $sendInvite, $auditLogger): User {
            $user = User::create($createUserRequest->safe()->only($fields));
            $user->forceFill(['email_verified_at' => now()])->save();

            $auditLogger->record(
                $sendInvite ? 'invite.created' : 'user.created',
                $user,
                sprintf($sendInvite ? 'Invited %s <%s> as %s.' : 'Created %s <%s> as %s.', $user->name, $user->email, $user->role->label()),
                context: ['role' => $user->role->value],
            );

            return $user;
        });

        if (! $sendInvite) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

            return to_route('admin.users.index');
        }

        $inviteUrl = URL::temporarySignedRoute(
            'auth.invite.accept',
            now()->addHours(48),
            ['user' => $user->id],
        );

        try {
            Mail::to($user)->send(new UserInvitation($user, $inviteUrl));
        } catch (TransportExceptionInterface $transportException) {
            Log::warning('The invitation email could not be sent.', [
                'user_id' => $user->id,
                'exception' => $transportException::class,
                'message' => $transportException->getMessage(),
            ]);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Created :email, but the invitation email could not be sent. Delete the user and invite them again once mail works.', ['email' => $user->email])]);

            return to_route('admin.users.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $user->email])]);

        return to_route('admin.users.index');
    }

    public function updateRole(UpdateUserRoleRequest $updateUserRoleRequest, User $user, ModifyUserAccess $modifyUserAccess, AuditLogger $auditLogger): RedirectResponse
    {
        abort_if($user->id === $updateUserRoleRequest->user()->id, 403);

        $previousRole = $user->role;
        $userRole = UserRole::from((string) $updateUserRoleRequest->validated('role'));

        try {
            DB::transaction(function () use ($modifyUserAccess, $auditLogger, $user, $userRole, $previousRole): void {
                $modifyUserAccess->changeRole($user, $userRole);

                if ($previousRole !== $userRole) {
                    $auditLogger->record(
                        'user.role_changed',
                        $user,
                        sprintf("Changed %s's role from %s to %s.", $user->name, $previousRole->label(), $userRole->label()),
                        ['role' => ['from' => $previousRole->value, 'to' => $userRole->value]],
                    );
                }
            });
        } catch (LastAdminException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('At least one admin must remain.')]);

            return to_route('admin.users.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User role updated.')]);

        return to_route('admin.users.index');
    }

    public function destroy(User $user, ModifyUserAccess $modifyUserAccess, AuditLogger $auditLogger): RedirectResponse
    {
        abort_if($user->id === request()->user()->id, 403);

        // An account that never accepted its invite (no password, no SSO) is a
        // pending invitation: deleting it revokes the invite.
        $pendingInvite = $user->password === null && $user->sso_provider === null && $user->invite_accepted_at === null;

        try {
            DB::transaction(function () use ($modifyUserAccess, $auditLogger, $user, $pendingInvite): void {
                $modifyUserAccess->delete($user);

                $auditLogger->record(
                    $pendingInvite ? 'invite.revoked' : 'user.deleted',
                    $user,
                    $pendingInvite
                        ? sprintf('Revoked the invitation for %s <%s>.', $user->name, $user->email)
                        : sprintf('Deleted %s <%s>.', $user->name, $user->email),
                    context: ['role' => $user->role->value],
                );
            });
        } catch (LastAdminException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('At least one admin must remain.')]);

            return to_route('admin.users.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }
}
