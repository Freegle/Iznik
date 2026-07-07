<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create
                            {--email= : Email address for the new user (required)}
                            {--name= : Full name of the new user (required)}
                            {--password= : Optional native login password}';

    protected $description = 'Create a new Freegle user with an email address';

    public function handle(): int
    {
        $email = $this->option('email');
        $name = $this->option('name');
        $password = $this->option('password');

        if (!$email) {
            $this->error('--email is required');
            return self::FAILURE;
        }

        if (!$name) {
            $this->error('--name is required');
            return self::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");
            return self::FAILURE;
        }

        $existing = DB::table('users_emails')->where('email', $email)->first();
        if ($existing) {
            $this->error("A user already exists with email: {$email} (user #{$existing->userid})");
            return self::FAILURE;
        }

        $userId = DB::table('users')->insertGetId([
            'fullname' => $name,
            'added' => now(),
        ]);

        DB::table('users_emails')->insert([
            'userid' => $userId,
            'email' => $email,
            'preferred' => 1,
            'backwards' => strrev($email),
            'added' => now(),
        ]);

        if ($password) {
            DB::table('users_logins')->insert([
                'userid' => $userId,
                'type' => 'Native',
                'uid' => $email,
                'credentials' => Hash::make($password),
                'added' => now(),
            ]);
        }

        $this->info("Created user #{$userId} ({$name} <{$email}>)");

        return self::SUCCESS;
    }
}
