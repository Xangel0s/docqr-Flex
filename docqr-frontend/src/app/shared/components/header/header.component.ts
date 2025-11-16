import { Component, Output, EventEmitter, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { DocqrService } from '../../../core/services/docqr.service';
import { NotificationService } from '../../../core/services/notification.service';
import { interval, Subscription } from 'rxjs';

/**
 * Componente Header con logo Geofal y navegación
 */
@Component({
  selector: 'app-header',
  standalone: true,
  imports: [CommonModule, RouterModule],
  templateUrl: './header.component.html',
  styleUrls: ['./header.component.scss']
})
export class HeaderComponent implements OnInit, OnDestroy {
  // Logo Geofal - usar placeholder por ahora
  logoPath = '/assets/images/geofal-logo-placeholder.svg';
  
  @Output() toggleSidebar = new EventEmitter<void>();

  // Estado de compresión
  hasCompressionAlert: boolean = false;
  compressionCount: number = 0;
  compressionMessage: string = '';
  showNotificationMenu: boolean = false;
  
  private compressionCheckInterval?: Subscription;

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService
  ) {}

  ngOnInit(): void {
    // Verificar estado de compresión al cargar
    this.checkCompressionStatus();
    
    // Verificar cada 5 minutos
    this.compressionCheckInterval = interval(5 * 60 * 1000).subscribe(() => {
      this.checkCompressionStatus();
    });
  }

  ngOnDestroy(): void {
    if (this.compressionCheckInterval) {
      this.compressionCheckInterval.unsubscribe();
    }
  }

  /**
   * Verificar estado de compresión
   */
  checkCompressionStatus(): void {
    this.docqrService.getCompressionStatus().subscribe({
      next: (response) => {
        if (response.success) {
          this.hasCompressionAlert = response.data.needs_compression;
          this.compressionCount = response.data.pending_count;
          this.compressionMessage = response.data.message;
        }
      },
      error: (error) => {
        console.error('Error al verificar estado de compresión:', error);
      }
    });
  }

  /**
   * Toggle del sidebar (menú hamburguesa)
   */
  onToggleSidebar(): void {
    this.toggleSidebar.emit();
  }

  /**
   * Toggle del menú de notificaciones
   */
  toggleNotificationMenu(): void {
    this.showNotificationMenu = !this.showNotificationMenu;
  }

  /**
   * Cerrar menú de notificaciones
   */
  closeNotificationMenu(): void {
    this.showNotificationMenu = false;
  }

  /**
   * Ver detalles de compresión
   */
  viewCompressionDetails(): void {
    this.closeNotificationMenu();
    this.notificationService.showInfo(
      `Hay ${this.compressionCount} documentos antiguos pendientes de compresión. Ejecuta el comando de compresión desde el servidor.`
    );
  }
}

