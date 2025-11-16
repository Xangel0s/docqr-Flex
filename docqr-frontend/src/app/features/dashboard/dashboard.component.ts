import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { DocqrService, StatsResponse } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';

/**
 * Componente Dashboard - Página principal con estadísticas
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, RouterModule, HeaderComponent, SidebarComponent],
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.scss']
})
export class DashboardComponent implements OnInit {
  stats: StatsResponse['data'] | null = null;
  loading: boolean = true;
  error: boolean = false;
  errorMessage: string = '';
  sidebarOpen: boolean = false; // En móvil: false por defecto, en desktop: siempre visible (manejado por CSS)

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadStats();
    // En desktop, el sidebar siempre está visible (manejado por CSS)
    // En móvil, inicia cerrado
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
  }

  /**
   * Cargar estadísticas
   */
  loadStats(): void {
    this.loading = true;
    this.error = false;
    this.errorMessage = '';
    
    this.docqrService.getStats().subscribe({
      next: (response: StatsResponse) => {
        if (response.success) {
          this.stats = response.data;
          this.error = false;
        } else {
          this.error = true;
          this.errorMessage = 'Error al cargar estadísticas';
          this.notificationService.showError('Error al cargar estadísticas');
          // Datos por defecto
          this.setDefaultStats();
        }
        this.loading = false;
      },
      error: (error) => {
        console.error('Error al cargar estadísticas:', error);
        this.error = true;
        this.errorMessage = error.error?.message || 'Error de conexión con el servidor';
        this.notificationService.showError('Error al cargar estadísticas. Verifica la conexión con el servidor.');
        this.loading = false;
        this.setDefaultStats();
      }
    });
  }

  /**
   * Establecer datos por defecto
   */
  private setDefaultStats(): void {
    this.stats = {
      total_documents: 0,
      total_scans: 0,
      scans_last_30_days: 0,
      completed_documents: 0,
      pending_documents: 0,
      last_upload: null,
      activity_by_folder: [],
      recent_documents: []
    };
  }

  /**
   * Recargar estadísticas
   */
  refreshStats(): void {
    this.loadStats();
  }

  /**
   * Navegar a subir documento
   */
  goToUpload(): void {
    this.router.navigate(['/upload']);
  }

  /**
   * Formatear número con separadores de miles
   */
  formatNumber(num: number): string {
    return num.toLocaleString('es-ES');
  }

  /**
   * Formatear fecha
   */
  formatDate(date: string | null): string {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('es-ES');
  }

  /**
   * Toggle del sidebar
   */
  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  /**
   * Cerrar sidebar
   */
  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }
}

