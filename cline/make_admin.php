<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$email = 'admin@localhost';

$user = User::where('email', $email)->first();

if ($user) {
    $user->update([
        'role' => User::ROLE_SUPER_ADMIN,
        'is_approved' => true,
        'approved_at' => now(),
    ]);
    echo "Existing user {$email} promoted to super_admin (id {$user->id})\n";
} else {
    $user = User::create([
        'name' => 'Admin',
        'email' => $email,
        'password' => Hash::make('password'),
        'role' => User::ROLE_SUPER_ADMIN,
        'is_approved' => true,
        'approved_at' => now(),
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();
    echo "Created super_admin {$email} (id {$user->id})\n";
}
