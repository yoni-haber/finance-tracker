<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

#[Description("Reset an existing user's password (public password reset is disabled)")]
#[Signature('app:reset-password
        {--email= : The email address of the user to reset}
        {--password= : The new password (min 8 characters)}')]
class ResetPasswordCommand extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?? text('Email', required: true);
        $password = $this->option('password') ?? promptPassword('New password', required: true);

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'string', 'email', 'max:255', 'exists:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
            ['email.exists' => 'No user exists with that email address.'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->firstOrFail();
        $user->forceFill(['password' => $password])->save();

        $this->components->info(sprintf('Password reset for "%s" <%s>.', $user->name, $user->email));

        return self::SUCCESS;
    }
}
