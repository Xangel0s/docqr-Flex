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
    if (this.authService.isAuthenticated()) {
      this.authService.checkAuth().subscribe({
        next: (response) => {
          if (!response.success) {
            if (this.router.url !== '/login') {
              this.router.navigate(['/login']);
            }
          }
        },
        error: () => {
          if (this.router.url !== '/login') {
          }
        }
      });
    }
  }
}

