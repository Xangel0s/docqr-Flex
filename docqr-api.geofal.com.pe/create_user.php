<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

try {
    $user = User::create([
        'username' => 'admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('password'),
        'name' => 'Admin User',
        'role' => 'admin',
        'is_active' => true
    ]);
    echo "User created successfully: " . $user->username;
} catch (\Exception $e) {
    echo "Error creating user: " . $e->getMessage();
}
