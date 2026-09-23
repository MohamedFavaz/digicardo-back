<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create {email? : The admin email address} {--password= : The admin password} {--name=Admin : The admin display name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or promote a user to Super Administrator';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Enter admin email address');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email address is required.');
            return 1;
        }

        $password = $this->option('password') ?: $this->secret('Enter admin password');
        if (!$password || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return 1;
        }

        $name = $this->option('name') ?: 'Digicardo Admin';

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
            $this->info("✓ Successfully promoted existing user [{$email}] to Super Administrator.");
        } else {
            User::create([
                'id' => (string) Str::ulid(),
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
            $this->info("✓ Successfully created new Super Administrator [{$email}].");
        }

        $this->newLine();
        $this->table(
            ['Field', 'Value'],
            [
                ['Name', $name],
                ['Email', $email],
                ['Role', 'admin'],
                ['Status', 'active'],
                ['Login URL', '/login or /admin/login'],
            ]
        );

        return 0;
    }
}
