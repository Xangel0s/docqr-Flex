<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para loguear requests de upload (solo para debugging)
 * Deshabilitar en producción o usar solo cuando sea necesario
 */
class LogRequest
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo loguear requests de upload para debugging
        if ($request->is('api/documents/*/attach-pdf') || $request->is('api/upload')) {
            Log::info('Request de upload recibido:', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'has_file' => $request->hasFile('file') || $request->hasFile('pdf'),
                'file_size' => $request->hasFile('file') 
                    ? $request->file('file')->getSize() 
                    : ($request->hasFile('pdf') ? $request->file('pdf')->getSize() : null),
                'content_type' => $request->header('Content-Type'),
                'content_length' => $request->header('Content-Length'),
                'all_inputs' => array_keys($request->all()),
            ]);
        }
        
        return $next($request);
    }
}

