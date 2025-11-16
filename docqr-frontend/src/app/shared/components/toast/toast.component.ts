import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NotificationService, Notification } from '../../../core/services/notification.service';

/**
 * Componente para mostrar notificaciones toast
 */
@Component({
  selector: 'app-toast',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './toast.component.html',
  styleUrls: ['./toast.component.scss']
})
export class ToastComponent implements OnInit {
  notifications: Notification[] = [];

  constructor(private notificationService: NotificationService) {}

  ngOnInit(): void {
    this.notificationService.getNotifications().forEach(() => {
      // Suscribirse a cambios (implementación simple)
      setInterval(() => {
        this.notifications = [...this.notificationService.getNotifications()];
      }, 100);
    });
  }

  /**
   * Remover notificación
   */
  remove(id: number): void {
    this.notificationService.remove(id);
    this.notifications = [...this.notificationService.getNotifications()];
  }

  /**
   * Obtener clase CSS según tipo
   */
  getNotificationClass(type: string): string {
    const classes: { [key: string]: string } = {
      success: 'bg-green-500 text-white',
      error: 'bg-red-500 text-white',
      warning: 'bg-yellow-500 text-white',
      info: 'bg-blue-500 text-white'
    };
    return classes[type] || classes['info'];
  }

  /**
   * Obtener icono según tipo
   */
  getIcon(type: string): string {
    const icons: { [key: string]: string } = {
      success: 'check_circle',
      error: 'error',
      warning: 'warning',
      info: 'info'
    };
    return icons[type] || icons['info'];
  }
}

