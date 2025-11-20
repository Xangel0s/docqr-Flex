import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { NotificationService } from '../../core/services/notification.service';
import { AuthService } from '../../core/services/auth.service';

/**
 * Componente de Configuración
 */
@Component({
  selector: 'app-settings',
  standalone: true,
  imports: [CommonModule, RouterModule, FormsModule, HeaderComponent, SidebarComponent],
  templateUrl: './settings.component.html',
  styleUrls: ['./settings.component.scss']
})
export class SettingsComponent implements OnInit {
  sidebarOpen: boolean = false;
  
  // Datos del perfil
  fullName: string = '';
  email: string = '';
  originalFullName: string = '';
  originalEmail: string = '';
  
  // Cambio de contraseña
  currentPassword: string = '';
  newPassword: string = '';
  confirmPassword: string = '';
  
  savingProfile: boolean = false;
  changingPassword: boolean = false;
  profileFormChanged: boolean = false;
  passwordFormChanged: boolean = false;
  loading: boolean = true;

  constructor(
    private notificationService: NotificationService,
    private authService: AuthService
  ) {}

  ngOnInit(): void {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
    this.loadUserData();
  }

  /**
   * Cargar datos del usuario desde el servidor
   */
  loadUserData(): void {
    this.loading = true;
    this.authService.checkAuth().subscribe({
      next: (response) => {
        if (response.success && response.data?.user) {
          const user = response.data.user;
          this.fullName = user.name || 'Administrador';
          this.email = user.email || '';
          this.originalFullName = this.fullName;
          this.originalEmail = this.email;
        } else {
          // Si no hay usuario, usar valores por defecto
          this.fullName = 'Administrador';
          this.email = '';
          this.originalFullName = this.fullName;
          this.originalEmail = this.email;
        }
        this.loading = false;
      },
      error: () => {
        // En caso de error, usar valores por defecto
        this.fullName = 'Administrador';
        this.email = '';
        this.originalFullName = this.fullName;
        this.originalEmail = this.email;
        this.loading = false;
      }
    });
  }

  /**
   * Detectar cambios en el formulario de perfil
   */
  onProfileChange(): void {
    this.profileFormChanged = (
      this.fullName !== this.originalFullName || 
      this.email !== this.originalEmail
    );
  }

  /**
   * Detectar cambios en el formulario de contraseña
   */
  onPasswordChange(): void {
    this.passwordFormChanged = true;
  }

  /**
   * Validar formulario de perfil
   */
  isProfileValid(): boolean {
    return !!this.fullName.trim();
  }

  /**
   * Validar formulario de contraseña
   */
  isPasswordValid(): boolean {
    if (!this.currentPassword.trim()) return false;
    if (!this.newPassword.trim()) return false;
    if (this.newPassword.length < 6) return false;
    if (this.newPassword !== this.confirmPassword) return false;
    return true;
  }

  /**
   * Guardar cambios del perfil
   */
  saveProfile(): void {
    if (!this.isProfileValid()) {
      this.notificationService.showError('Por favor completa todos los campos obligatorios');
      return;
    }

    this.savingProfile = true;
    
    this.authService.updateProfile(this.fullName.trim(), this.email.trim() || undefined).subscribe({
      next: (response) => {
        this.savingProfile = false;
        if (response.success) {
          this.originalFullName = this.fullName;
          this.originalEmail = this.email;
          this.profileFormChanged = false;
          this.notificationService.showSuccess(response.message || 'Perfil actualizado exitosamente');
        } else {
          this.notificationService.showError(response.message || 'Error al actualizar el perfil');
        }
      },
      error: (error) => {
        this.savingProfile = false;
        const errorMessage = error.error?.message || error.message || 'Error al actualizar el perfil';
        this.notificationService.showError(errorMessage);
      }
    });
  }

  /**
   * Cancelar cambios del perfil
   */
  cancelProfile(): void {
    this.fullName = this.originalFullName;
    this.email = this.originalEmail;
    this.profileFormChanged = false;
  }

  /**
   * Actualizar contraseña
   */
  updatePassword(): void {
    if (!this.isPasswordValid()) {
      if (!this.currentPassword.trim()) {
        this.notificationService.showError('Por favor ingresa tu contraseña actual');
        return;
      }
      if (!this.newPassword.trim()) {
        this.notificationService.showError('Por favor ingresa una nueva contraseña');
        return;
      }
      if (this.newPassword.length < 6) {
        this.notificationService.showError('La contraseña debe tener al menos 6 caracteres');
        return;
      }
      if (this.newPassword !== this.confirmPassword) {
        this.notificationService.showError('Las contraseñas no coinciden');
        return;
      }
      return;
    }

    this.changingPassword = true;
    
    this.authService.updatePassword(
      this.currentPassword,
      this.newPassword,
      this.confirmPassword
    ).subscribe({
      next: (response) => {
        this.changingPassword = false;
        if (response.success) {
          this.currentPassword = '';
          this.newPassword = '';
          this.confirmPassword = '';
          this.passwordFormChanged = false;
          this.notificationService.showSuccess(response.message || 'Contraseña actualizada exitosamente');
        } else {
          this.notificationService.showError(response.message || 'Error al actualizar la contraseña');
        }
      },
      error: (error) => {
        this.changingPassword = false;
        const errorMessage = error.error?.message || error.message || 'Error al actualizar la contraseña';
        this.notificationService.showError(errorMessage);
      }
    });
  }

  /**
   * Cancelar cambio de contraseña
   */
  cancelPassword(): void {
    this.currentPassword = '';
    this.newPassword = '';
    this.confirmPassword = '';
    this.passwordFormChanged = false;
  }

  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }
}

