<?php
/**
 * Script temporal para verificar conexión a base de datos
 */

$host = 'localhost';
$database = 'grersced_docqr';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$database;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    
    echo "✅ Conexión exitosa a la base de datos: $database\n";
    
    // Verificar si hay tablas
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📊 Tablas encontradas: " . count($tables) . "\n";
    if (count($tables) > 0) {
        echo "   Tablas: " . implode(', ', $tables) . "\n";
    }
    
    // Verificar tabla qr_files específicamente
    if (in_array('qr_files', $tables)) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM qr_files");
        $result = $stmt->fetch();
        echo "📄 Documentos en qr_files: " . $result['count'] . "\n";
    } else {
        echo "⚠️  La tabla 'qr_files' no existe. Necesitas ejecutar las migraciones.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
    echo "   Verifica que:\n";
    echo "   - MySQL esté corriendo en XAMPP\n";
    echo "   - La base de datos '$database' exista\n";
    echo "   - El usuario '$username' tenga permisos\n";
    exit(1);
}

