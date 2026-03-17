<?php
// public/auth/login-download-file/index.php

// 1. Capturamos la URL tal cual viene
$requestUri = $_SERVER['REQUEST_URI'];

// 2. Limpieza básica para obtener solo el código final
// Quitamos la ruta de la carpeta para que quede solo el archivo solicitado
$codigo = str_replace('/auth/login-download-file/', '', $requestUri);

// 3. Decodificamos (quita los %20, %C2, etc.)
$codigo = urldecode($codigo);

// 4. Limpieza de seguridad (quita slashes extra)
$codigo = trim($codigo, '/');

// --- LOG RAPIDO PARA VER SI FUNCIONA ---
// Escribe en el error_log de PHP (búscalo en cPanel o en la misma carpeta)
error_log("Intento de acceso QR detectado: " . $codigo);

if (empty($codigo) || $codigo == 'index.php') {
    echo "Esperando código...";
    exit;
}

// 5. REDIRECCION FINAL
$destino = "https://docqr.geofal.com.pe/login?doc_id=" . urlencode($codigo);
header("Location: " . $destino);
exit;
?>