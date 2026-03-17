import { Injectable } from '@angular/core';
import { HttpInterceptor, HttpRequest, HttpHandler, HttpEvent } from '@angular/common/http';
import { Observable } from 'rxjs';

/**
 * Interceptor para agregar automáticamente el header X-Frontend-Origin
 * Esto permite que el backend sepa desde qué dominio se está accediendo
 * y genere URLs correctas para los QR codes
 */
@Injectable()
export class FrontendOriginInterceptor implements HttpInterceptor {
  intercept(req: HttpRequest<any>, next: HttpHandler): Observable<HttpEvent<any>> {
    // Obtener el origen del frontend (protocolo + host + puerto)
    const frontendOrigin = window.location.origin;
    
    // Clonar la solicitud y agregar el header X-Frontend-Origin
    const clonedReq = req.clone({
      setHeaders: {
        'X-Frontend-Origin': frontendOrigin
      }
    });
    
    return next.handle(clonedReq);
  }
}

