import { Injectable } from '@angular/core';
import { Subject, Observable } from 'rxjs';

/**
 * Tipo de notificación
 */
export type NotificationType = 'success' | 'error' | 'warning' | 'info';

/**
 * Interfaz de notificación
 */
export interface Notification {
  id: number;
  type: NotificationType;
  message: string;
  duration?: number;
}

/**
 * Servicio para mostrar notificaciones toast
 */
@Injectable({
  providedIn: 'root'
})
export class NotificationService {
  private notifications: Notification[] = [];
  private nextId = 0;
  private notificationsSubject = new Subject<Notification[]>();

  /**
   * Observable para suscribirse a cambios en las notificaciones
   */
  get notifications$(): Observable<Notification[]> {
    return this.notificationsSubject.asObservable();
  }

  /**
   * Obtener lista de notificaciones
   */
  getNotifications(): Notification[] {
    return this.notifications;
  }

  /**
   * Notificar cambios a los suscriptores
   */
  private notify(): void {
    this.notificationsSubject.next([...this.notifications]);
  }

  /**
   * Mostrar notificación de éxito
   */
  showSuccess(message: string, duration: number = 3000): void {
    this.show('success', message, duration);
  }

  /**
   * Mostrar notificación de error
   */
  showError(message: string, duration: number = 5000): void {
    this.show('error', message, duration);
  }

  /**
   * Mostrar notificación de advertencia
   */
  showWarning(message: string, duration: number = 4000): void {
    this.show('warning', message, duration);
  }

  /**
   * Mostrar notificación de información
   */
  showInfo(message: string, duration: number = 3000): void {
    this.show('info', message, duration);
  }

  /**
   * Mostrar notificación
   */
  private show(type: NotificationType, message: string, duration: number): void {
    const notification: Notification = {
      id: this.nextId++,
      type,
      message,
      duration
    };

    this.notifications.push(notification);
    this.notify();

    // Auto-remover después de la duración
    if (duration > 0) {
      setTimeout(() => {
        this.remove(notification.id);
      }, duration);
    }
  }

  /**
   * Remover notificación
   */
  remove(id: number): void {
    this.notifications = this.notifications.filter(n => n.id !== id);
    this.notify();
  }

  /**
   * Limpiar todas las notificaciones
   */
  clear(): void {
    this.notifications = [];
    this.notify();
  }
}

