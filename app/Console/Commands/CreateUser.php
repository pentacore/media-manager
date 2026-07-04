<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Description('Create a new user with a chosen role. Bypasses the invite flow.')]
#[Signature('users:create
                            {--name= : The user\'s display name}
                            {--email= : The user\'s email address}
                            {--password= : The user\'s password (will prompt securely if omitted)}
                            {--role= : User role (admin, member, viewer)}')]
class CreateUser extends Command
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function handle(): int
    {
        $name = $this->option('name') ?: text(
            label: 'Name',
            required: true,
        );

        $email = $this->option('email') ?: text(
            label: 'Email',
            required: true,
            validate: fn (string $value): ?string => Validator::make(
                ['email' => $value],
                ['email' => $this->emailRules()],
            )->errors()->first('email') ?: null,
        );

        $password = $this->option('password') ?: password(
            label: 'Password',
            required: true,
        );

        $role = UserRole::tryFrom((string) $this->option('role')) ?? UserRole::from(select(
            label: 'Role',
            options: array_combine(
                array_map(fn (UserRole $userRole): string => $userRole->value, UserRole::cases()),
                array_map(fn (UserRole $userRole): string => $userRole->label(), UserRole::cases()),
            ),
            default: UserRole::Member->value,
        ));

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ], [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'invite_accepted_at' => now(),
        ]);

        // email_verified_at is not mass-assignable; set directly so the user can sign in immediately.
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info(sprintf('Created %s (#%d) as %s.', $user->email, $user->id, $role->label()));

        return self::SUCCESS;
    }
}
