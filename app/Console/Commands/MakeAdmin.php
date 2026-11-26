<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Grants operator access: php artisan app:make-admin you@example.com */
class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email}';

    protected $description = 'Give a user access to the admin report';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        $user->update(['is_admin' => true]);
        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
