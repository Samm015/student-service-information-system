```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateProductionAdmin extends Command
{
    protected $signature = 'ssis:create-admin';

    protected $description = 'Create or update the production administrator account';

    public function handle(): int
    {
        if (! app()->environment('production')) {
            $this->error('This command is only available in production.');

            return self::FAILURE;
        }

        $name = $this->ask('Administrator name');
        $email = strtolower(trim($this->ask('Administrator email')));
        $password = $this->secret('Administrator password');

        if (! $name || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Please provide a valid name and email.');

            return self::FAILURE;
        }

        if (! $password || strlen($password) < 12) {
            $this->error('Password must be at least 12 characters long.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user && ! $this->confirm(
            'An account with this email already exists. Update it to an administrator?'
        )) {
            $this->info('No changes made.');

            return self::SUCCESS;
        }

        $user ??= new User();

        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->role = User::ROLE_ADMIN;
        $user->department_id = null;
        $user->is_active = true;
        $user->save();

        $this->info('Administrator account saved successfully.');

        return self::SUCCESS;
    }
}
```