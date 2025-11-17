import { Injectable } from '@angular/core';
import { CanActivate, Router, ActivatedRouteSnapshot, RouterStateSnapshot } from '@angular/router';
import { AuthService } from '../services/auth.service';
import { Observable } from 'rxjs';
import { map, take } from 'rxjs/operators';

@Injectable({
  providedIn: 'root'
})
export class AuthGuard implements CanActivate {
  constructor(
    private authService: AuthService,
    private router: Router
  ) {}

  canActivate(
    route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot
  ): Observable<boolean> | Promise<boolean> | boolean {
    // Verificación rápida: si hay token, permitir acceso inmediato
    // La verificación del servidor se hace en segundo plano
    if (this.authService.isAuthenticated()) {
      // Verificar sesión en el servidor (sin bloquear la navegación)
      // Solo si falla, redirigir a login
      this.authService.checkAuth().pipe(
        take(1)
      ).subscribe({
        next: (response) => {
          if (!response.success) {
            this.router.navigate(['/login']);
          }
        },
        error: () => {
          // Solo redirigir si hay error de red, no si el token es inválido
          // (el token inválido se maneja en checkAuth)
        }
      });
      
      // Permitir acceso inmediato si hay token
      return true;
    } else {
      this.router.navigate(['/login']);
      return false;
    }
  }
}

