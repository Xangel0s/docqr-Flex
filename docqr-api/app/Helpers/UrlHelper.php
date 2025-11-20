<?php

namespace App\Helpers;

use Illuminate\Http\Request;

/**
 * Helper para generar URLs que respetan el protocolo de la solicitud actual
 * 
 * Esto es especialmente útil cuando se accede a través de ngrok (HTTPS)
 * pero APP_URL está configurado como HTTP
 */
class UrlHelper
{
    /**
     * Generar URL que respeta el protocolo de la solicitud actual
     * 
     * Soporta dos escenarios:
     * 1. Frontend y backend en el mismo dominio: usa el dominio de la solicitud
     * 2. Frontend y backend en dominios diferentes: usa FRONTEND_URL de .env o header X-Frontend-Origin
     * 
     * @param string $path Ruta relativa (ej: "/api/files/qr/abc123")
     * @param Request|null $request Request actual (opcional, se detecta automáticamente)
     * @return string URL completa con protocolo correcto
     */
    public static function url(string $path, ?Request $request = null): string
    {
        if (!$request) {
            $request = request();
        }

        if (!$request) {
            return url($path);
        }

        // Si la ruta es de API, siempre usar el host del backend
        $isApiRoute = str_starts_with($path, '/api/');
        
        $protocol = 'http';
        $scheme = $request->getScheme();
        $forwardedProto = $request->header('X-Forwarded-Proto');
        $forwardedSsl = $request->header('X-Forwarded-Ssl');
        $isSecure = $request->server('HTTPS');
        
        if ($forwardedProto === 'https') {
            $protocol = 'https';
        } elseif ($forwardedSsl === 'on') {
            $protocol = 'https';
        } elseif ($isSecure === 'on' || $isSecure === '1') {
            $protocol = 'https';
        } elseif ($scheme === 'https') {
            $protocol = 'https';
        } elseif ($request->secure()) {
            $protocol = 'https';
        } elseif (strpos($request->getHost(), 'ngrok') !== false || 
                strpos($request->header('X-Forwarded-Host', ''), 'ngrok') !== false) {
            $protocol = 'https';
        }
        
        $host = null;
        $useLocalhost = env('USE_LOCALHOST', false);
        
        // Para rutas de API, siempre usar el host del backend
        if ($isApiRoute) {
            if ($useLocalhost && in_array(env('APP_ENV', 'production'), ['local', 'development'])) {
                $host = 'localhost:8000';
                $protocol = 'http';
            } else {
                // Usar el host del request actual (backend)
                $host = $request->header('X-Forwarded-Host');
                if (!$host) {
                    $host = $request->getHost();
                }
                $host = preg_replace('/:\d+$/', '', $host);
                
                // Si estamos en desarrollo local, asegurar que use el puerto correcto
                if (env('APP_ENV') === 'local' && ($host === 'localhost' || strpos($host, '127.0.0.1') !== false)) {
                    $port = $request->getPort();
                    if ($port && $port != 80 && $port != 443) {
                        $host .= ':' . $port;
                    } elseif (!$port || $port == 80) {
                        $host .= ':8000';
                    }
                } else {
                    $port = $request->getPort();
                    if ($port && $port != 80 && $port != 443 && strpos($host, ':') === false) {
                        $host .= ':' . $port;
                    }
                }
            }
            
            $path = '/' . ltrim($path, '/');
            return $protocol . '://' . $host . $path;
        }
        
        // Para rutas no-API, usar la lógica original (frontend)
        $frontendUrl = env('FRONTEND_URL');
        
        if ($useLocalhost && in_array(env('APP_ENV', 'production'), ['local', 'development'])) {
            $host = 'localhost:4200';
            $protocol = 'http';
            $path = '/' . ltrim($path, '/');
            return $protocol . '://' . $host . $path;
        }
        
        if ($frontendUrl) {
            $frontendUrl = rtrim($frontendUrl, '/');
            $parsedFrontend = parse_url($frontendUrl);
            if (isset($parsedFrontend['host'])) {
                $host = $parsedFrontend['host'];
                if (isset($parsedFrontend['scheme'])) {
                    $protocol = $parsedFrontend['scheme'];
                }
                if (isset($parsedFrontend['port']) && $parsedFrontend['port'] != 80 && $parsedFrontend['port'] != 443) {
                    $host .= ':' . $parsedFrontend['port'];
                }
                $path = '/' . ltrim($path, '/');
                return $protocol . '://' . $host . $path;
            }
        }
        
        if (!$host) {
            $frontendOrigin = $request->header('X-Frontend-Origin');
            if ($frontendOrigin) {
                $parsedOrigin = parse_url($frontendOrigin);
                if (isset($parsedOrigin['host'])) {
                    $host = $parsedOrigin['host'];
                    if (isset($parsedOrigin['scheme'])) {
                        $protocol = $parsedOrigin['scheme'];
                    }
                    if (isset($parsedOrigin['port']) && $parsedOrigin['port'] != 80 && $parsedOrigin['port'] != 443) {
                        $host .= ':' . $parsedOrigin['port'];
                    }
                }
            }
        }
        
        if (!$host) {
            $host = $request->header('X-Forwarded-Host');
            if (!$host) {
                $host = $request->getHost();
            }
            $host = preg_replace('/:\d+$/', '', $host);
            
            if ($protocol === 'https' && ($host === 'localhost' || strpos($host, '127.0.0.1') !== false)) {
                $fullUrl = $request->fullUrl();
                $parsedUrl = parse_url($fullUrl);
                if (isset($parsedUrl['host']) && $parsedUrl['host'] !== 'localhost' && strpos($parsedUrl['host'], '127.0.0.1') === false) {
                    $host = $parsedUrl['host'];
                    if (isset($parsedUrl['port']) && $parsedUrl['port'] != 443) {
                        $host .= ':' . $parsedUrl['port'];
                    }
                }
            }
        }
        
        $port = $request->getPort();
        $baseUrl = $protocol . '://' . $host;
        
        if ($port && $port != 80 && $port != 443 && strpos($host, ':') === false) {
            $baseUrl .= ':' . $port;
        }
        
        $path = '/' . ltrim($path, '/');
        return $baseUrl . $path;
    }

    public static function relativeUrl(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}

