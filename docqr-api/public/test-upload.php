<?php
/**
 * Script de diagnóstico para probar uploads
 * 
 * USO: Subir este archivo a public/ y acceder desde navegador
 * Luego probar subir un archivo desde el formulario
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Upload - Diagnóstico</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #ffebee; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .success { background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 10px 0; }
        form { background: #f5f5f5; padding: 20px; border-radius: 5px; }
        input[type="file"] { margin: 10px 0; }
        button { background: #2196F3; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #1976D2; }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico de Upload</h1>
    
    <div class="info">
        <h2>Configuración PHP Actual:</h2>
        <ul>
            <li><strong>upload_max_filesize:</strong> <?php echo ini_get('upload_max_filesize'); ?></li>
            <li><strong>post_max_size:</strong> <?php echo ini_get('post_max_size'); ?></li>
            <li><strong>memory_limit:</strong> <?php echo ini_get('memory_limit'); ?></li>
            <li><strong>max_execution_time:</strong> <?php echo ini_get('max_execution_time'); ?> segundos</li>
            <li><strong>max_input_time:</strong> <?php echo ini_get('max_input_time'); ?> segundos</li>
            <li><strong>file_uploads:</strong> <?php echo ini_get('file_uploads') ? 'Habilitado' : 'Deshabilitado'; ?></li>
            <li><strong>max_file_uploads:</strong> <?php echo ini_get('max_file_uploads'); ?></li>
        </ul>
    </div>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_file'])) {
        $file = $_FILES['test_file'];
        
        echo '<div class="info">';
        echo '<h2>📤 Información del Archivo Recibido:</h2>';
        echo '<ul>';
        echo '<li><strong>Nombre:</strong> ' . htmlspecialchars($file['name']) . '</li>';
        echo '<li><strong>Tamaño:</strong> ' . number_format($file['size'] / 1024 / 1024, 2) . ' MB (' . $file['size'] . ' bytes)</li>';
        echo '<li><strong>Tipo MIME:</strong> ' . htmlspecialchars($file['type']) . '</li>';
        echo '<li><strong>Error de PHP:</strong> ' . $file['error'] . ' (' . ($file['error'] === UPLOAD_ERR_OK ? 'OK' : 'ERROR') . ')</li>';
        echo '<li><strong>Archivo temporal:</strong> ' . htmlspecialchars($file['tmp_name']) . '</li>';
        echo '</ul>';
        echo '</div>';
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            // Verificar que es PDF
            $isPdf = false;
            if ($file['type'] === 'application/pdf' || $file['type'] === 'application/x-pdf') {
                $isPdf = true;
            } else {
                // Verificar extensión
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($extension === 'pdf') {
                    $isPdf = true;
                }
            }
            
            // Verificar header del archivo
            $handle = fopen($file['tmp_name'], 'rb');
            $header = fread($handle, 4);
            fclose($handle);
            $isPdfHeader = ($header === '%PDF');
            
            if ($isPdf && $isPdfHeader) {
                echo '<div class="success">';
                echo '<h2>✅ Archivo PDF Válido</h2>';
                echo '<p>El archivo se recibió correctamente y es un PDF válido.</p>';
                echo '</div>';
            } else {
                echo '<div class="error">';
                echo '<h2>❌ Problema Detectado</h2>';
                if (!$isPdf) {
                    echo '<p><strong>Tipo MIME incorrecto:</strong> ' . htmlspecialchars($file['type']) . '</p>';
                }
                if (!$isPdfHeader) {
                    echo '<p><strong>Header del archivo:</strong> ' . htmlspecialchars($header) . ' (debería ser %PDF)</p>';
                }
                echo '</div>';
            }
        } else {
            echo '<div class="error">';
            echo '<h2>❌ Error al Subir Archivo</h2>';
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'El archivo excede upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE => 'El archivo excede MAX_FILE_SIZE del formulario',
                UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
                UPLOAD_ERR_NO_FILE => 'No se subió ningún archivo',
                UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal',
                UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco',
                UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo',
            ];
            echo '<p><strong>Error:</strong> ' . ($errorMessages[$file['error']] ?? 'Error desconocido: ' . $file['error']) . '</p>';
            echo '</div>';
        }
    }
    ?>

    <form method="POST" enctype="multipart/form-data">
        <h2>Probar Upload:</h2>
        <p>Selecciona un PDF para probar la subida:</p>
        <input type="file" name="test_file" accept=".pdf,application/pdf" required>
        <br><br>
        <button type="submit">Probar Upload</button>
    </form>

    <div class="info" style="margin-top: 30px;">
        <h2>📝 Instrucciones:</h2>
        <ol>
            <li>Prueba subir tu PDF de 3.2MB desde este formulario</li>
            <li>Verifica los valores de configuración PHP arriba</li>
            <li>Si hay errores, revisa el mensaje específico</li>
            <li>Si el upload funciona aquí pero no en la app, el problema está en la validación de Laravel</li>
        </ol>
    </div>

    <div class="info" style="margin-top: 20px;">
        <h2>🔧 Soluciones Comunes:</h2>
        <ul>
            <li><strong>Error UPLOAD_ERR_INI_SIZE:</strong> Aumentar <code>upload_max_filesize</code> en php.ini</li>
            <li><strong>Error UPLOAD_ERR_FORM_SIZE:</strong> Aumentar <code>post_max_size</code> en php.ini</li>
            <li><strong>Tipo MIME incorrecto:</strong> El navegador puede reportar tipo incorrecto, pero el archivo puede ser válido</li>
            <li><strong>Header no es %PDF:</strong> El archivo no es un PDF válido o está corrupto</li>
        </ul>
    </div>
</body>
</html>

