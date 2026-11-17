<?php

declare(strict_types=1);

/**
 * Creates the MoonShine V panel administrator on the first boot.
 * Runs inside the moonshine-migrate service, after `artisan migrate --force`.
 * Credentials come from the MSV_ADMIN_* environment variables (see .env.example).
 */

use Illuminate\Support\Facades\Hash;
use MoonShine\Laravel\Models\MoonshineUser;
use MoonShine\Laravel\Models\MoonshineUserRole;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name     = getenv('MSV_ADMIN_NAME') ?: 'Administrator';
$email    = getenv('MSV_ADMIN_EMAIL') ?: 'admin@localhost';
$password = getenv('MSV_ADMIN_PASSWORD') ?: 'change-me';

if (MoonshineUser::query()->exists()) {
    echo "MoonShine V: administrator already exists, skipping.\n";

    exit(0);
}

MoonshineUser::query()->forceCreate([
    'name' => $name,
    'email' => $email,
    // MoonshineUser does not hash in a cast, so hash explicitly here.
    'password' => Hash::make($password),
    'moonshine_user_role_id' => MoonshineUserRole::DEFAULT_ROLE_ID,
]);

echo "MoonShine V: administrator created ({$email}).\n";
