import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from './core/services/auth.service';

@Component({
  selector: 'app-root',
  templateUrl: './app.component.html',
  styleUrls: ['./app.component.scss']
})
export class AppComponent implements OnInit {
  title = 'Geofal - Sistema de Gestión de Documentos con QR';

  constructor(
    private authService: AuthService,
    private router: Router
  ) {}

  ngOnInit(): void {
    // Verificar autenticación al iniciar la aplicación (en segundo plano, sin bloquear)
    if (this.authService.isAuthenticated()) {
      // Verificar en segundo plano sin bloquear la carga inicial
      this.authService.checkAuth().subscribe({
        next: (response) => {
          if (!response.success) {
            // Token inválido, redirigir a login solo si estamos en una ruta protegida
            if (this.router.url !== '/login') {
              this.router.navigate(['/login']);
            }
          }
        },
        error: () => {
          // Solo redirigir si hay error de red y no estamos en login
          if (this.router.url !== '/login') {
            // No redirigir automáticamente por errores de red
            // El guard manejará la autenticación
          }
        }
      });
    }
  }
}

