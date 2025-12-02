import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, Validators, ReactiveFormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { NotificationService } from '../../core/services/notification.service';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss']
})
export class LoginComponent implements OnInit {
  loginForm: FormGroup;
  loading = false;
  errorMessage = '';

  constructor(
    private fb: FormBuilder,
    private authService: AuthService,
    private router: Router,
    private notificationService: NotificationService
  ) {
    this.loginForm = this.fb.group({
      username: ['', [Validators.required]],
      password: ['', [Validators.required]]
    });
  }

  ngOnInit(): void {
    if (this.authService.isAuthenticated()) {
      this.router.navigate(['/']);
    }
  }

  onSubmit(): void {
    if (this.loginForm.invalid) {
      this.markFormGroupTouched(this.loginForm);
      return;
    }

    this.loading = true;
    this.errorMessage = '';

    const { username, password } = this.loginForm.value;

    this.authService.login(username, password).subscribe({
      next: (response) => {
        if (response && response.success) {
          setTimeout(() => {
            // Verificar que esté guardado antes de navegar
            if (this.authService.isAuthenticated()) {
              this.notificationService.showSuccess('Bienvenido a Geofal');
              this.loading = false;
              // Navegar inmediatamente - el token ya está guardado
              this.router.navigate(['/']).then(() => {
                // Navegación exitosa
              }).catch(() => {
                window.location.href = '/';
              });
            } else {
              if (!environment.production) {
                console.error('Login exitoso pero token no guardado. Respuesta:', response);
                console.error('Token en localStorage:', localStorage.getItem('geofal_token'));
              }
              this.errorMessage = 'Error al guardar la sesión. Intenta nuevamente.';
              this.loading = false;
              this.notificationService.showError(this.errorMessage);
            }
          }, 50); // Pequeño delay para asegurar que localStorage se actualice
        } else {
          this.errorMessage = response?.message || 'Error al iniciar sesión';
          this.loading = false;
          this.notificationService.showError(this.errorMessage);
        }
      },
      error: (error) => {
        if (!environment.production) {
          console.error('Error en login:', error);
        }
        this.errorMessage = error?.error?.message || 'Usuario o contraseña incorrectos';
        this.loading = false;
        this.notificationService.showError(this.errorMessage);
      }
    });
  }

  private markFormGroupTouched(formGroup: FormGroup): void {
    Object.keys(formGroup.controls).forEach(key => {
      const control = formGroup.get(key);
      control?.markAsTouched();
    });
  }
}

