import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { NotificationService } from '../../core/services/notification.service';

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
  fullName: string = 'Administrador';
  email: string = 'admin@docqr.com';
  
  // Cambio de contraseña
  currentPassword: string = '';
  newPassword: string = '';
  confirmPassword: string = '';
  
  // Estados
  savingProfile: boolean = false;
  changingPassword: boolean = false;
  profileFormChanged: boolean = false;
  passwordFormChanged: boolean = false;

  constructor(private notificationService: NotificationService) {}

  ngOnInit(): void {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
  }

  /**
   * Detectar cambios en el formulario de perfil
   */
  onProfileChange(): void {
    this.profileFormChanged = true;
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
    
    // Simular guardado (TODO: conectar con API)
    setTimeout(() => {
      this.savingProfile = false;
      this.profileFormChanged = false;
      this.notificationService.showSuccess('Perfil actualizado exitosamente');
    }, 1000);
  }

  /**
   * Cancelar cambios del perfil
   */
  cancelProfile(): void {
    this.fullName = 'Administrador';
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
    
    // Simular actualización (TODO: conectar con API)
    setTimeout(() => {
      this.changingPassword = false;
      this.currentPassword = '';
      this.newPassword = '';
      this.confirmPassword = '';
      this.passwordFormChanged = false;
      this.notificationService.showSuccess('Contraseña actualizada exitosamente');
    }, 1000);
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

