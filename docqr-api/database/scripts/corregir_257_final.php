<?php

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$qrId = '6uR0bZFsr0YHsRgtjKqMub8R6eCKF3ps';
$nuevoFilePath = 'uploads/IN/202509/N-1257-25-CO12/f1afc7f209f382c00d4714975e18aba762d007531b70adfb7e08a696f2f0df5c.pdf';

if (Storage::disk('local')->exists($nuevoFilePath)) {
    DB::table('qr_files')
        ->where('qr_id', $qrId)
        ->update(['file_path' => $nuevoFilePath]);
    
    echo "✅ ACTUALIZADO: {$nuevoFilePath}\n";
} else {
    echo "❌ Archivo no existe\n";
}

