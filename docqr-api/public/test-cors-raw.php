<?php
// PHP puro, sin Laravel ni Frameworks.
// Este script intenta forzar la cabecera que te está dando problemas.

// Reemplaza docqr.geofal.com.pe con la URL de tu frontend.
header('Access-Control-Allow-Origin: https://docqr.geofal.com.pe'); 
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, x-frontend-origin, X-Frontend-Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Credentials: true');
header('HTTP/1.1 200 OK');
exit();
?>
