<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

class CreateUser extends Command
{
    protected $signature = 'users:create {email} {--name=} {--password=}';

    protected $description = 'Create an API user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) ($this->option('name') ?: $email);
        $pw = $this->option('password');
        $plain = is_string($pw) && $pw !== '' ? $pw : password('Password');

        User::create(['name' => $name, 'email' => $email, 'password' => $plain]);
        $this->info("User {$email} created.");

        return self::SUCCESS;
    }
}
