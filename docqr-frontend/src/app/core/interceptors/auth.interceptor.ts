import { Injectable } from '@angular/core';
import {
  HttpRequest,
  HttpHandler,
  HttpEvent,
  HttpInterceptor
} from '@angular/common/http';
import { Observable } from 'rxjs';

@Injectable()
export class AuthInterceptor implements HttpInterceptor {

  constructor() {}

  intercept(request: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    // Asegurar que todas las peticiones incluyan cookies (withCredentials)
    // Esto es necesario para que las cookies de sesión se envíen automáticamente
    // Excluir rutas públicas que no necesitan autenticación
    
    const isPublicRoute = request.url.includes('/view/') || 
                          request.url.includes('/files/') ||
                          request.url.includes('/auth/login');
    
    const clonedRequest = request.clone({
      setHeaders: {},
      withCredentials: true // Importante: enviar cookies en todas las peticiones
    });
    
    return next.handle(clonedRequest);
  }
}

