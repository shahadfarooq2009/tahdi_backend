<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Console\Command;

class SetUserPasswordCommand extends Command
{
    protected $signature = 'user:set-password
        {email : Existing account email in user_profiles}
        {--force : Allow running outside local/testing environments}';

    protected $description = 'Set a Laravel password for an existing legacy account (preserves id, role, and profile)';

    public function handle(AuthService $auth): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to run outside local/testing. Re-run with --force if you are sure.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_deleted', false)
            ->first();

        if (! $user) {
            $this->error("No active user found for email: {$email}");

            return self::FAILURE;
        }

        $this->line("Target user: {$user->email}");
        $this->line("User id: {$user->id}");
        $this->line('Role: '.($user->role ?? 'user'));
        $this->line('Password currently set: '.(filled($user->password) ? 'yes' : 'no'));

        $password = (string) $this->secret('New password (min 6 characters)');
        $confirmation = (string) $this->secret('Confirm new password');

        if ($password !== $confirmation) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        if (strlen($password) < 6) {
            $this->error('Password must be at least 6 characters.');

            return self::FAILURE;
        }

        $beforeRole = $user->role;
        $beforeId = $user->id;

        $auth->setPasswordForExistingUser($user, $password);

        $user->refresh();

        if ($user->id !== $beforeId || $user->role !== $beforeRole) {
            $this->error('Password update altered user id or role. Manual review required.');

            return self::FAILURE;
        }

        $this->info("Password updated for {$user->email} (id={$user->id}, role={$user->role}).");

        return self::SUCCESS;
    }
}
