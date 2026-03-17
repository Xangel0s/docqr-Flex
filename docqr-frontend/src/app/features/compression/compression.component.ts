import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DocqrService } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { environment } from '../../../environments/environment';

/**
 * Interfaz para grupo de compresión
 */
interface CompressionGroup {
  type: string;
  month: string;
  month_formatted: string;
  count: number;
  total_size_mb: number;
  documents: any[];
}

/**
 * Componente para gestión manual de compresión
 */
@Component({
  selector: 'app-compression',
  standalone: true,
  imports: [CommonModule, FormsModule, HeaderComponent, SidebarComponent],
  templateUrl: './compression.component.html',
  styleUrls: ['./compression.component.scss']
})
export class CompressionComponent implements OnInit {
  sidebarOpen: boolean = false;
  loading: boolean = false;
  groups: CompressionGroup[] = [];
  selectedGroups: Set<string> = new Set();
  monthsBack: number = 6;

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService
  ) {}

  ngOnInit(): void {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
    this.loadGroups();
  }

  /**
   * Cargar grupos de documentos comprimibles
   */
  loadGroups(): void {
    this.loading = true;
    this.docqrService.getCompressionList(this.monthsBack).subscribe({
      next: (response) => {
        if (response.success) {
          this.groups = response.data;
        } else {
          this.notificationService.showError('Error al cargar grupos');
        }
        this.loading = false;
      },
      error: (error) => {
        console.error('Error:', error);
        this.notificationService.showError('Error al cargar grupos de compresión');
        this.loading = false;
      }
    });
  }

  /**
   * Seleccionar/deseleccionar grupo
   */
  toggleGroup(type: string, month: string): void {
    const key = `${type}|${month}`;
    if (this.selectedGroups.has(key)) {
      this.selectedGroups.delete(key);
    } else {
      this.selectedGroups.add(key);
    }
  }

  /**
   * Verificar si un grupo está seleccionado
   */
  isGroupSelected(type: string, month: string): boolean {
    return this.selectedGroups.has(`${type}|${month}`);
  }

  /**
   * Comprimir grupos seleccionados
   */
  compressSelected(): void {
    if (this.selectedGroups.size === 0) {
      this.notificationService.showWarning('Selecciona al menos un grupo para comprimir');
      return;
    }

    if (!confirm(`¿Comprimir ${this.selectedGroups.size} grupo(s) seleccionado(s)?`)) {
      return;
    }

    this.loading = true;
    let completed = 0;
    let errors = 0;

    const compressPromises = Array.from(this.selectedGroups).map(key => {
      const [type, month] = key.split('|');
      return this.docqrService.compressByMonth(type, month).toPromise()
        .then(() => completed++)
        .catch(() => errors++);
    });

    Promise.all(compressPromises).then(() => {
      this.loading = false;
      this.selectedGroups.clear();
      
      if (errors === 0) {
        this.notificationService.showSuccess(`Comprimidos ${completed} grupo(s) exitosamente`);
      } else {
        this.notificationService.showWarning(`Comprimidos ${completed} grupo(s), ${errors} error(es)`);
      }
      
      this.loadGroups();
    });
  }

  /**
   * Comprimir un grupo específico
   */
  compressGroup(type: string, month: string): void {
    if (!confirm(`¿Comprimir documentos de ${type} del mes ${this.formatMonth(month)}?`)) {
      return;
    }

    this.loading = true;
    this.docqrService.compressByMonth(type, month).subscribe({
      next: (response) => {
        if (response.success) {
          this.notificationService.showSuccess(response.message);
          this.loadGroups();
        } else {
          this.notificationService.showError(response.message || 'Error al comprimir');
        }
        this.loading = false;
      },
      error: (error) => {
        console.error('Error:', error);
        this.notificationService.showError('Error al comprimir documentos');
        this.loading = false;
      }
    });
  }

  /**
   * Descargar ZIP comprimido
   */
  downloadZip(type: string, month: string): void {
    const url = `${environment.apiUrl}/compression/download?type=${type}&month=${month}`;
    window.open(url, '_blank');
  }

  /**
   * Formatear mes para mostrar
   */
  formatMonth(month: string): string {
    if (month.length === 6) {
      const year = month.substring(0, 4);
      const monthNum = month.substring(4, 2);
      const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                     'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
      return `${months[parseInt(monthNum) - 1]} ${year}`;
    }
    return month;
  }

  /**
   * Obtener tipo de documento en español
   */
  getTypeLabel(type: string): string {
    const types: { [key: string]: string } = {
      'CE': 'Certificado',
      'IN': 'Informe de Ensayo',
      'SU': 'Suplemento',
      'OTROS': 'Otros'
    };
    return types[type] || type;
  }

  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }
}

