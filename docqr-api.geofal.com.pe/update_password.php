<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

try {
    $user = User::where('username', 'admin')->first();
    if ($user) {
        $user->password = Hash::make('password');
        $user->save();
        echo "Password updated for user: " . $user->username;
    } else {
        echo "User not found";
    }
} catch (\Exception $e) {
    echo "Error updating user: " . $e->getMessage();
}
