import { Component, OnInit, AfterViewInit, ViewChild, ElementRef, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { DocqrService, StatsResponse } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { Chart, ChartConfiguration, registerables } from 'chart.js';

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
export class DashboardComponent implements OnInit, AfterViewInit, OnDestroy {
  @ViewChild('scanChartCanvas', { static: false }) scanChartCanvas!: ElementRef<HTMLCanvasElement>;
  
  stats: StatsResponse['data'] | null = null;
  loading: boolean = true;
  error: boolean = false;
  errorMessage: string = '';
  sidebarOpen: boolean = false; // En móvil: false por defecto, en desktop: siempre visible (manejado por CSS)
  
  private scanChart: Chart | null = null;
  private lastLoadTime: number = 0;
  private readonly CACHE_DURATION = 30000; // 30 segundos para dashboard (más largo porque no cambia tan frecuentemente)

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService,
    private router: Router
  ) {
    // Registrar componentes de Chart.js
    Chart.register(...registerables);
  }

  ngOnInit(): void {
    this.loadStats();
    // En desktop, el sidebar siempre está visible (manejado por CSS)
    // En móvil, inicia cerrado
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
  }

  ngAfterViewInit(): void {
  }

  ngOnDestroy(): void {
    if (this.scanChart) {
      this.scanChart.destroy();
    }
  }

  /**
   * Cargar estadísticas (con caché para evitar recargas innecesarias)
   */
  loadStats(force: boolean = false): void {
    // Verificar caché: si se cargó hace menos de 30 segundos y no es forzado, no recargar
    const now = Date.now();
    if (!force && (now - this.lastLoadTime) < this.CACHE_DURATION && this.stats) {
      this.loading = false;
      return;
    }
    
    this.loading = true;
    this.error = false;
    this.errorMessage = '';
    
    this.docqrService.getStats().subscribe({
      next: (response: StatsResponse) => {
        if (response.success) {
          this.stats = response.data;
          this.error = false;
          this.lastLoadTime = Date.now(); // Actualizar tiempo de carga
          // Crear gráficos después de cargar los datos
          setTimeout(() => {
            this.createCharts();
          }, 100);
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
   * Recargar estadísticas (forzar recarga)
   */
  refreshStats(): void {
    this.loadStats(true); // Forzar recarga al hacer clic en "Actualizar"
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

  /**
   * Crear gráficos
   */
  private createCharts(): void {
    if (!this.stats) return;

    // Destruir gráfico anterior si existe
    if (this.scanChart) {
      this.scanChart.destroy();
    }

    // Crear gráfico de actividad de escaneos
    if (this.scanChartCanvas && this.stats.activity_by_folder && this.stats.activity_by_folder.length > 0) {
      const ctx = this.scanChartCanvas.nativeElement.getContext('2d');
      if (ctx) {
        const labels = this.stats.activity_by_folder.slice(0, 10).map(f => f.folder_name);
        const data = this.stats.activity_by_folder.slice(0, 10).map(f => f.total_scans);

        const config: ChartConfiguration = {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [{
              label: 'Escaneos',
              data: data,
              backgroundColor: 'rgba(59, 130, 246, 0.5)',
              borderColor: 'rgba(59, 130, 246, 1)',
              borderWidth: 1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: false
              },
              tooltip: {
                callbacks: {
                  label: (context) => {
                    const value = context.parsed.y;
                    return `Escaneos: ${value !== null && value !== undefined ? value.toLocaleString('es-ES') : '0'}`;
                  }
                }
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  callback: function(value) {
                    return value.toLocaleString('es-ES');
                  }
                }
              },
              x: {
                ticks: {
                  maxRotation: 45,
                  minRotation: 45
                }
              }
            }
          }
        };

        this.scanChart = new Chart(ctx, config);
      }
    }
  }
}

