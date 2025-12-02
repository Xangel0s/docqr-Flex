<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Middleware para confiar en proxies (ngrok, load balancers, etc.)
 * 
 * Esto es CRÍTICO para producción cuando la aplicación está detrás de un proxy:
 * - ngrok (desarrollo/pruebas)
 * - Load balancers (AWS, Azure, GCP, etc.)
 * - Reverse proxies (Nginx, Apache como proxy)
 * 
 * Sin este middleware, Laravel no detectará correctamente:
 * - HTTPS (X-Forwarded-Proto)
 * - IP real del cliente (X-Forwarded-For)
 * - Host real (X-Forwarded-Host)
 */
class TrustProxies extends Middleware
{
    /**
     * Los proxies en los que confiar.
     * 
     * Para desarrollo con ngrok: usar '*' para confiar en todos
     * Para producción: especificar IPs específicas de los load balancers
     * 
     * @var array<int, string>|string|null
     */
    protected $proxies = '*'; // Confiar en todos los proxies (ngrok, load balancers, etc.)

    /**
     * Los headers que deben usarse para detectar proxies.
     * 
     * Estos son los headers estándar que envían los proxies:
     * - X-Forwarded-For: IP real del cliente
     * - X-Forwarded-Proto: Protocolo (http/https)
     * - X-Forwarded-Host: Host real
     * - X-Forwarded-Port: Puerto real
     * 
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB; // Para AWS ELB
}
