import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Subscription } from 'rxjs';
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
export class ToastComponent implements OnInit, OnDestroy {
  notifications: Notification[] = [];
  private subscription?: Subscription;

  constructor(private notificationService: NotificationService) {}

  ngOnInit(): void {
    // Suscribirse a cambios en las notificaciones
    this.subscription = this.notificationService.notifications$.subscribe(
      (notifications) => {
        this.notifications = notifications;
      }
    );
    
    // Cargar notificaciones iniciales
    this.notifications = [...this.notificationService.getNotifications()];
  }

  ngOnDestroy(): void {
    if (this.subscription) {
      this.subscription.unsubscribe();
    }
  }

  /**
   * Remover notificación
   */
  remove(id: number): void {
    this.notificationService.remove(id);
  }

  /**
   * Obtener clase CSS según tipo
   */
  getNotificationClass(type: string): string {
    const classes: { [key: string]: string } = {
      success: 'bg-green-500 text-white border-l-4 border-green-600',
      error: 'bg-red-500 text-white border-l-4 border-red-600',
      warning: 'bg-yellow-500 text-white border-l-4 border-yellow-600',
      info: 'bg-blue-500 text-white border-l-4 border-blue-600'
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

  /**
   * TrackBy function para optimizar el renderizado
   */
  trackByNotificationId(index: number, notification: Notification): number {
    return notification.id;
  }
}

