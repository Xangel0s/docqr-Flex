<?php

$rootDir = realpath(__DIR__ . '/../..');
$autoloadPath = $rootDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require $autoloadPath;

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app = require_once $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n=== VERIFICAR DOCUMENTOS CON CÓDIGO 257-25 CO12 ===\n\n";

// Buscar en tabla antigua
echo "📋 En tabla 'document' (antigua):\n";
$docsAntiguos = DB::table('document')
    ->where(function($query) {
        $query->where('code', 'like', '%257-25%CO12%')
              ->orWhere('code', 'like', '%257-25 CO12%')
              ->orWhere('code', 'like', '%1257-25-CO12%');
    })
    ->get();

foreach ($docsAntiguos as $doc) {
    echo "   ID: {$doc->document_id}\n";
    echo "   Código: {$doc->code}\n";
    echo "   Archivo: {$doc->file_name}\n";
    echo "   Fecha: {$doc->creation_date}\n";
    echo "   password_file: {$doc->password_file}\n";
    echo "\n";
}

// Buscar en tabla nueva
echo "\n📋 En tabla 'qr_files' (nueva):\n";
$docsNuevos = DB::table('qr_files')
    ->where(function($query) {
        $query->where('original_filename', 'like', '%257%CO12%')
              ->orWhere('folder_name', 'like', '%257%CO12%')
              ->orWhere('folder_name', 'like', '%1257%CO12%');
    })
    ->get();

foreach ($docsNuevos as $doc) {
    echo "   qr_id: {$doc->qr_id}\n";
    echo "   folder_name: {$doc->folder_name}\n";
    echo "   Archivo: {$doc->original_filename}\n";
    echo "   file_path: {$doc->file_path}\n";
    echo "   document_id: {$doc->document_id}\n";
    echo "\n";
}

echo "\n";

