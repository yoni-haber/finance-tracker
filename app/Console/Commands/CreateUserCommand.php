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

#[Description('Create a pre-verified user account (public registration is disabled)')]
#[Signature('app:create-user
        {--name= : The user\'s display name}
        {--email= : The user\'s email address}
        {--password= : The user\'s password (min 8 characters)}')]
class CreateUserCommand extends Command
{
    public function handle(): int
    {
        $name = $this->option('name') ?? text('Name', required: true);
        $email = $this->option('email') ?? text('Email', required: true);
        $password = $this->option('password') ?? promptPassword('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

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
        ]);

        // email_verified_at is guarded against mass assignment, so set it
        // directly: manually-created accounts are trusted and skip verification.
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info(sprintf('Created user "%s" <%s> (id: %d).', $user->name, $user->email, $user->id));

        return self::SUCCESS;
    }
}
