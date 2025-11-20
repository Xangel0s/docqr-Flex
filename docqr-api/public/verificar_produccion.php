<?php
/**
 * Script de Verificación de Configuración para Producción
 * DocQR - Geofal
 * 
 * Ejecutar: https://docqr-api.geofal.com.pe/verificar_produccion.php
 */

header('Content-Type: text/html; charset=utf-8');

// Cargar variables de entorno si existe
$envFile = __DIR__ . '/../.env';
$envVars = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $envVars[trim($key)] = trim($value);
    }
}

function getEnv($key, $default = '') {
    global $envVars;
    return $envVars[$key] ?? $default;
}

function checkPassed($condition) {
    return $condition ? '✅ CORRECTO' : '❌ ERROR';
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

function convertToBytes($value) {
    $value = trim($value);
    $last = strtolower($value[strlen($value)-1]);
    $value = (int) $value;
    switch($last) {
        case 'g': $value *= 1024;
        case 'm': $value *= 1024;
        case 'k': $value *= 1024;
    }
    return $value;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Producción - DocQR Geofal</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }
        .content {
            padding: 30px;
        }
        .section {
            margin-bottom: 30px;
            border-left: 4px solid #667eea;
            padding-left: 20px;
        }
        .section h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.5em;
        }
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            margin: 8px 0;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid transparent;
        }
        .check-item.pass {
            border-left-color: #28a745;
            background: #d4edda;
        }
        .check-item.fail {
            border-left-color: #dc3545;
            background: #f8d7da;
        }
        .check-item.warning {
            border-left-color: #ffc107;
            background: #fff3cd;
        }
        .check-label {
            font-weight: 500;
            color: #333;
        }
        .check-value {
            font-family: 'Courier New', monospace;
            color: #666;
            font-size: 0.9em;
        }
        .check-status {
            font-weight: bold;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.85em;
        }
        .status-pass { color: #28a745; }
        .status-fail { color: #dc3545; }
        .status-warning { color: #ffc107; }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .error-box {
            background: #ffebee;
            border-left: 4px solid #f44336;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .success-box {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            border-top: 1px solid #dee2e6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Verificación de Producción</h1>
            <p>DocQR - Geofal</p>
            <p style="font-size: 0.9em; margin-top: 10px;">
                <?php echo date('Y-m-d H:i:s'); ?>
            </p>
        </div>

        <div class="content">
            
            <!-- Información General -->
            <div class="section">
                <h2>📋 Información General</h2>
                <div class="check-item">
                    <span class="check-label">Versión de PHP</span>
                    <span class="check-value"><?php echo PHP_VERSION; ?></span>
                </div>
                <div class="check-item">
                    <span class="check-label">Sistema Operativo</span>
                    <span class="check-value"><?php echo PHP_OS; ?></span>
                </div>
                <div class="check-item">
                    <span class="check-label">Servidor Web</span>
                    <span class="check-value"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido'; ?></span>
                </div>
                <div class="check-item">
                    <span class="check-label">Dominio Actual</span>
                    <span class="check-value"><?php echo $_SERVER['HTTP_HOST'] ?? 'Desconocido'; ?></span>
                </div>
            </div>

            <!-- Variables de Entorno -->
            <div class="section">
                <h2>⚙️ Variables de Entorno (.env)</h2>
                <?php
                $envExists = file_exists($envFile);
                $appEnv = getEnv('APP_ENV');
                $appDebug = getEnv('APP_DEBUG');
                $appUrl = getEnv('APP_URL');
                $frontendUrl = getEnv('FRONTEND_URL');
                $corsOrigins = getEnv('CORS_ALLOWED_ORIGINS');
                ?>
                
                <div class="check-item <?php echo $envExists ? 'pass' : 'fail'; ?>">
                    <span class="check-label">Archivo .env existe</span>
                    <span class="check-status <?php echo $envExists ? 'status-pass' : 'status-fail'; ?>">
                        <?php echo checkPassed($envExists); ?>
                    </span>
                </div>

                <?php if ($envExists): ?>
                    <div class="check-item <?php echo $appEnv === 'production' ? 'pass' : 'warning'; ?>">
                        <span class="check-label">APP_ENV</span>
                        <span class="check-value"><?php echo $appEnv ?: 'No configurado'; ?></span>
                        <span class="check-status <?php echo $appEnv === 'production' ? 'status-pass' : 'status-warning'; ?>">
                            <?php echo checkPassed($appEnv === 'production'); ?>
                        </span>
                    </div>

                    <div class="check-item <?php echo strtolower($appDebug) === 'false' ? 'pass' : 'fail'; ?>">
                        <span class="check-label">APP_DEBUG</span>
                        <span class="check-value"><?php echo $appDebug ?: 'No configurado'; ?></span>
                        <span class="check-status <?php echo strtolower($appDebug) === 'false' ? 'status-pass' : 'status-fail'; ?>">
                            <?php echo checkPassed(strtolower($appDebug) === 'false'); ?>
                        </span>
                    </div>

                    <div class="check-item <?php echo strpos($appUrl, 'https://docqr-api.geofal.com.pe') !== false ? 'pass' : 'warning'; ?>">
                        <span class="check-label">APP_URL</span>
                        <span class="check-value"><?php echo $appUrl ?: 'No configurado'; ?></span>
                        <span class="check-status <?php echo strpos($appUrl, 'https://docqr-api.geofal.com.pe') !== false ? 'status-pass' : 'status-warning'; ?>">
                            <?php echo checkPassed(strpos($appUrl, 'https://docqr-api.geofal.com.pe') !== false); ?>
                        </span>
                    </div>

                    <div class="check-item <?php echo strpos($frontendUrl, 'https://docqr.geofal.com.pe') !== false ? 'pass' : 'warning'; ?>">
                        <span class="check-label">FRONTEND_URL</span>
                        <span class="check-value"><?php echo $frontendUrl ?: 'No configurado'; ?></span>
                        <span class="check-status <?php echo strpos($frontendUrl, 'https://docqr.geofal.com.pe') !== false ? 'status-pass' : 'status-warning'; ?>">
                            <?php echo checkPassed(strpos($frontendUrl, 'https://docqr.geofal.com.pe') !== false); ?>
                        </span>
                    </div>

                    <div class="check-item <?php echo !empty($corsOrigins) ? 'pass' : 'warning'; ?>">
                        <span class="check-label">CORS_ALLOWED_ORIGINS</span>
                        <span class="check-value"><?php echo $corsOrigins ?: 'No configurado'; ?></span>
                        <span class="check-status <?php echo !empty($corsOrigins) ? 'status-pass' : 'status-warning'; ?>">
                            <?php echo checkPassed(!empty($corsOrigins)); ?>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="error-box">
                        <strong>⚠️ Advertencia:</strong> El archivo .env no existe. Copia .env.production a .env y configúralo.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Configuración PHP -->
            <div class="section">
                <h2>🔧 Configuración PHP</h2>
                <?php
                $uploadMaxFilesize = ini_get('upload_max_filesize');
                $postMaxSize = ini_get('post_max_size');
                $memoryLimit = ini_get('memory_limit');
                $maxExecutionTime = ini_get('max_execution_time');
                $maxInputTime = ini_get('max_input_time');
                
                $uploadBytes = convertToBytes($uploadMaxFilesize);
                $postBytes = convertToBytes($postMaxSize);
                $memoryBytes = convertToBytes($memoryLimit);
                
                $uploadOk = $uploadBytes >= convertToBytes('500M');
                $postOk = $postBytes >= convertToBytes('510M');
                $memoryOk = $memoryBytes >= convertToBytes('1024M');
                $executionOk = $maxExecutionTime == 0 || $maxExecutionTime >= 600;
                $inputTimeOk = $maxInputTime == -1 || $maxInputTime >= 600;
                ?>
                
                <div class="check-item <?php echo $uploadOk ? 'pass' : 'fail'; ?>">
                    <span class="check-label">upload_max_filesize</span>
                    <span class="check-value"><?php echo $uploadMaxFilesize; ?> (<?php echo formatBytes($uploadBytes); ?>)</span>
                    <span class="check-status <?php echo $uploadOk ? 'status-pass' : 'status-fail'; ?>">
                        <?php echo checkPassed($uploadOk); ?>
                    </span>
                </div>

                <div class="check-item <?php echo $postOk ? 'pass' : 'fail'; ?>">
                    <span class="check-label">post_max_size</span>
                    <span class="check-value"><?php echo $postMaxSize; ?> (<?php echo formatBytes($postBytes); ?>)</span>
                    <span class="check-status <?php echo $postOk ? 'status-pass' : 'status-fail'; ?>">
                        <?php echo checkPassed($postOk); ?>
                    </span>
                </div>

                <div class="check-item <?php echo $memoryOk ? 'pass' : 'fail'; ?>">
                    <span class="check-label">memory_limit</span>
                    <span class="check-value"><?php echo $memoryLimit; ?> (<?php echo formatBytes($memoryBytes); ?>)</span>
                    <span class="check-status <?php echo $memoryOk ? 'status-pass' : 'status-fail'; ?>">
                        <?php echo checkPassed($memoryOk); ?>
                    </span>
                </div>

                <div class="check-item <?php echo $executionOk ? 'pass' : 'fail'; ?>">
                    <span class="check-label">max_execution_time</span>
                    <span class="check-value"><?php echo $maxExecutionTime; ?> segundos</span>
                    <span class="check-status <?php echo $executionOk ? 'status-pass' : 'status-fail'; ?>">
                        <?php echo checkPassed($executionOk); ?>
                    </span>
                </div>

                <div class="check-item <?php echo $inputTimeOk ? 'pass' : 'fail'; ?>">
                    <span class="check-label">max_input_time</span>
                    <span class="check-value"><?php echo $maxInputTime; ?> segundos</span>
                    <span class="check-status <?php echo $inputTimeOk ? 'status-pass' : 'status-fail'; ?>">
                        <?php echo checkPassed($inputTimeOk); ?>
                    </span>
                </div>

                <?php if (!$uploadOk || !$postOk || !$memoryOk): ?>
                    <div class="error-box">
                        <strong>⚠️ Configuración insuficiente:</strong><br>
                        Para manejar archivos de hasta 500MB, configura:<br>
                        <code>upload_max_filesize = 500M</code><br>
                        <code>post_max_size = 510M</code><br>
                        <code>memory_limit = 1024M</code>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Extensiones PHP -->
            <div class="section">
                <h2>🔌 Extensiones PHP Requeridas</h2>
                <?php
                $requiredExtensions = [
                    'mbstring' => 'Manejo de strings multibyte',
                    'openssl' => 'Encriptación y SSL',
                    'pdo' => 'Conexión a base de datos',
                    'pdo_mysql' => 'Driver MySQL',
                    'curl' => 'Peticiones HTTP',
                    'zip' => 'Compresión de archivos',
                    'gd' => 'Procesamiento de imágenes',
                    'xml' => 'Procesamiento XML',
                    'json' => 'Manejo de JSON',
                    'fileinfo' => 'Información de archivos'
                ];

                foreach ($requiredExtensions as $ext => $desc):
                    $loaded = extension_loaded($ext);
                ?>
                    <div class="check-item <?php echo $loaded ? 'pass' : 'fail'; ?>">
                        <span class="check-label"><?php echo $ext; ?></span>
                        <span class="check-value"><?php echo $desc; ?></span>
                        <span class="check-status <?php echo $loaded ? 'status-pass' : 'status-fail'; ?>">
                            <?php echo checkPassed($loaded); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Permisos de Directorios -->
            <div class="section">
                <h2>📁 Permisos de Directorios</h2>
                <?php
                $dirs = [
                    '../storage' => 'Storage (logs, cache, archivos)',
                    '../bootstrap/cache' => 'Bootstrap Cache',
                    '../storage/app' => 'Archivos de la aplicación',
                    '../storage/logs' => 'Logs del sistema'
                ];

                foreach ($dirs as $dir => $desc):
                    $path = realpath(__DIR__ . '/' . $dir);
                    $exists = $path && is_dir($path);
                    $writable = $exists && is_writable($path);
                    $perms = $exists ? substr(sprintf('%o', fileperms($path)), -4) : 'N/A';
                ?>
                    <div class="check-item <?php echo $writable ? 'pass' : 'fail'; ?>">
                        <span class="check-label"><?php echo $desc; ?></span>
                        <span class="check-value">
                            <?php echo $exists ? 'Existe' : 'No existe'; ?> | 
                            Permisos: <?php echo $perms; ?> | 
                            <?php echo $writable ? 'Escribible' : 'No escribible'; ?>
                        </span>
                        <span class="check-status <?php echo $writable ? 'status-pass' : 'status-fail'; ?>">
                            <?php echo checkPassed($writable); ?>
                        </span>
                    </div>
                <?php endforeach; ?>

                <?php
                $allWritable = true;
                foreach ($dirs as $dir => $desc) {
                    $path = realpath(__DIR__ . '/' . $dir);
                    if (!$path || !is_writable($path)) {
                        $allWritable = false;
                        break;
                    }
                }
                if (!$allWritable):
                ?>
                    <div class="error-box">
                        <strong>⚠️ Permisos insuficientes:</strong><br>
                        Ejecuta: <code>chmod -R 775 storage bootstrap/cache</code>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Base de Datos -->
            <div class="section">
                <h2>🗄️ Base de Datos</h2>
                <?php
                $dbHost = getEnv('DB_HOST', '127.0.0.1');
                $dbDatabase = getEnv('DB_DATABASE');
                $dbUsername = getEnv('DB_USERNAME');
                $dbPassword = getEnv('DB_PASSWORD');
                
                $dbConfigured = !empty($dbDatabase) && !empty($dbUsername);
                $dbConnection = false;
                $dbError = '';

                if ($dbConfigured && extension_loaded('pdo_mysql')) {
                    try {
                        $pdo = new PDO(
                            "mysql:host=$dbHost;dbname=$dbDatabase",
                            $dbUsername,
                            $dbPassword,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                        );
                        $dbConnection = true;
                        
                        // Verificar tabla qr_files
                        $stmt = $pdo->query("SHOW TABLES LIKE 'qr_files'");
                        $tableExists = $stmt->rowCount() > 0;
                        
                        if ($tableExists) {
                            $stmt = $pdo->query("SELECT COUNT(*) as count FROM qr_files");
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            $docCount = $result['count'];
                        }
                    } catch (PDOException $e) {
                        $dbError = $e->getMessage();
                    }
                }
                ?>
                
                <div class="check-item <?php echo $dbConfigured ? 'pass' : 'warning'; ?>">
                    <span class="check-label">Base de datos configurada</span>
                    <span class="check-value"><?php echo $dbDatabase ?: 'No configurada'; ?></span>
                    <span class="check-status <?php echo $dbConfigured ? 'status-pass' : 'status-warning'; ?>">
                        <?php echo checkPassed($dbConfigured); ?>
                    </span>
                </div>

                <?php if ($dbConfigured): ?>
                    <div class="check-item <?php echo $dbConnection ? 'pass' : 'fail'; ?>">
                        <span class="check-label">Conexión a base de datos</span>
                        <span class="check-value">
                            <?php echo $dbConnection ? "Conectado a $dbDatabase" : "Error: " . substr($dbError, 0, 50); ?>
                        </span>
                        <span class="check-status <?php echo $dbConnection ? 'status-pass' : 'status-fail'; ?>">
                            <?php echo checkPassed($dbConnection); ?>
                        </span>
                    </div>

                    <?php if ($dbConnection && isset($tableExists)): ?>
                        <div class="check-item <?php echo $tableExists ? 'pass' : 'warning'; ?>">
                            <span class="check-label">Tabla qr_files existe</span>
                            <span class="check-value">
                                <?php echo $tableExists ? "Sí (Documentos: $docCount)" : 'No existe - Ejecutar migraciones'; ?>
                            </span>
                            <span class="check-status <?php echo $tableExists ? 'status-pass' : 'status-warning'; ?>">
                                <?php echo checkPassed($tableExists); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Resumen Final -->
            <div class="section">
                <h2>📊 Resumen de Verificación</h2>
                <?php
                $allChecks = [
                    'Archivo .env existe' => $envExists,
                    'APP_ENV = production' => $appEnv === 'production',
                    'APP_DEBUG = false' => strtolower($appDebug) === 'false',
                    'PHP upload_max_filesize >= 500M' => $uploadOk,
                    'PHP post_max_size >= 510M' => $postOk,
                    'PHP memory_limit >= 1024M' => $memoryOk,
                    'Directorios escribibles' => $allWritable,
                    'Base de datos conectada' => $dbConnection
                ];
                
                $passedCount = count(array_filter($allChecks));
                $totalCount = count($allChecks);
                $percentage = round(($passedCount / $totalCount) * 100);
                
                $statusClass = $percentage >= 80 ? 'success-box' : ($percentage >= 50 ? 'info-box' : 'error-box');
                ?>
                
                <div class="<?php echo $statusClass; ?>">
                    <h3 style="margin-bottom: 10px;">
                        <?php if ($percentage >= 80): ?>
                            ✅ Sistema listo para producción
                        <?php elseif ($percentage >= 50): ?>
                            ⚠️ Sistema parcialmente configurado
                        <?php else: ?>
                            ❌ Sistema requiere configuración
                        <?php endif; ?>
                    </h3>
                    <p>
                        <strong><?php echo $passedCount; ?> de <?php echo $totalCount; ?></strong> verificaciones pasadas 
                        (<?php echo $percentage; ?>%)
                    </p>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Verificación</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allChecks as $check => $passed): ?>
                            <tr>
                                <td><?php echo $check; ?></td>
                                <td style="color: <?php echo $passed ? '#28a745' : '#dc3545'; ?>; font-weight: bold;">
                                    <?php echo $passed ? '✅ Correcto' : '❌ Error'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($percentage < 100): ?>
                <div class="info-box">
                    <strong>📝 Próximos pasos:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <?php if (!$envExists): ?>
                            <li>Copiar <code>.env.production</code> a <code>.env</code> y configurarlo</li>
                        <?php endif; ?>
                        <?php if ($appEnv !== 'production'): ?>
                            <li>Cambiar <code>APP_ENV=production</code> en .env</li>
                        <?php endif; ?>
                        <?php if (strtolower($appDebug) !== 'false'): ?>
                            <li>Cambiar <code>APP_DEBUG=false</code> en .env</li>
                        <?php endif; ?>
                        <?php if (!$uploadOk || !$postOk || !$memoryOk): ?>
                            <li>Configurar PHP: <code>upload_max_filesize=500M</code>, <code>post_max_size=510M</code>, <code>memory_limit=1024M</code></li>
                        <?php endif; ?>
                        <?php if (!$allWritable): ?>
                            <li>Dar permisos: <code>chmod -R 775 storage bootstrap/cache</code></li>
                        <?php endif; ?>
                        <?php if (!$dbConnection): ?>
                            <li>Configurar y verificar conexión a base de datos</li>
                        <?php endif; ?>
                        <?php if ($dbConnection && isset($tableExists) && !$tableExists): ?>
                            <li>Ejecutar migraciones: <code>php artisan migrate --force</code></li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

        </div>

        <div class="footer">
            <p><strong>DocQR - Sistema de Gestión de Documentos con QR</strong></p>
            <p>Geofal | <?php echo date('Y'); ?></p>
            <p style="margin-top: 10px; font-size: 0.9em;">
                Para más información, consulta <code>DESPLIEGUE_GEOFAL.md</code>
            </p>
        </div>
    </div>
</body>
</html>

