<?php
/**
 * Script temporal para verificar conexión a base de datos
 */

$host = 'localhost';
$dbname = 'grersced_docqr';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Conexión exitosa a la base de datos: $dbname\n\n";
    
    // Verificar tablas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "📊 Tablas encontradas: " . count($tables) . "\n";
    
    if (count($tables) > 0) {
        echo "\nLista de tablas:\n";
        foreach ($tables as $table) {
            $count = $pdo->query("SELECT COUNT(*) as count FROM `$table`")->fetch()['count'];
            echo "  - $table ($count registros)\n";
        }
    } else {
        echo "⚠️  No hay tablas en la base de datos. Es necesario ejecutar las migraciones.\n";
    }
    
    // Verificar tablas específicas de Laravel
    $requiredTables = ['qr_files', 'users', 'sessions', 'cache'];
    echo "\n🔍 Verificación de tablas requeridas:\n";
    foreach ($requiredTables as $table) {
        $exists = in_array($table, $tables);
        echo "  " . ($exists ? "✅" : "❌") . " $table\n";
    }
    
    exit(0);
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
    echo "\nDetalles:\n";
    echo "  - Host: $host\n";
    echo "  - Base de datos: $dbname\n";
    echo "  - Usuario: $username\n";
    echo "  - Contraseña: " . (empty($password) ? "(vacía)" : "(configurada)") . "\n";
    exit(1);
}

