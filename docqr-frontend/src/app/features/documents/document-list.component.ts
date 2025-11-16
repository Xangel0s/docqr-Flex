import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { DocqrService, Document, DocumentsResponse } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';

/**
 * Componente para listar documentos
 */
@Component({
  selector: 'app-document-list',
  standalone: true,
  imports: [CommonModule, RouterModule, FormsModule, HeaderComponent, SidebarComponent],
  templateUrl: './document-list.component.html',
  styleUrls: ['./document-list.component.scss']
})
export class DocumentListComponent implements OnInit {
  sidebarOpen: boolean = false;
  documents: Document[] = [];
  loading: boolean = true;
  searchTerm: string = '';
  currentPage: number = 1;
  totalPages: number = 1;
  totalDocuments: number = 0;
  stats = {
    total: 0,
    totalScans: 0,
    errors: 0,
    pending: 0
  };

  // Filtros
  showFilters: boolean = false;
  filters = {
    type: 'all', // all, CE, IN, SU
    status: 'all', // all, completed, uploaded, failed
    dateFrom: '',
    dateTo: '',
    scansFilter: 'all', // all, none, with_scans, most_scanned
    sortBy: 'created_at',
    sortOrder: 'desc' as 'asc' | 'desc'
  };
  
  activeFiltersCount: number = 0;

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService,
    private router: Router
  ) {}

  ngOnInit(): void {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
    this.loadDocuments();
    this.loadStats();
  }

  /**
   * Cargar documentos
   */
  loadDocuments(): void {
    this.loading = true;
    this.updateActiveFiltersCount();
    
    this.docqrService.getDocuments(undefined, this.searchTerm, this.currentPage, this.filters).subscribe({
      next: (response: DocumentsResponse) => {
        if (response.success) {
          this.documents = response.data;
          this.currentPage = response.meta.current_page;
          this.totalPages = response.meta.last_page;
          this.totalDocuments = response.meta.total;
        } else {
          this.notificationService.showError('Error al cargar documentos');
        }
        this.loading = false;
      },
      error: (error) => {
        console.error('Error al cargar documentos:', error);
        this.notificationService.showError('Error al cargar documentos. Verifica la conexión con el servidor.');
        this.loading = false;
        this.documents = [];
      }
    });
  }

  /**
   * Calcular cantidad de filtros activos
   */
  updateActiveFiltersCount(): void {
    let count = 0;
    if (this.filters.type !== 'all') count++;
    if (this.filters.status !== 'all') count++;
    if (this.filters.dateFrom) count++;
    if (this.filters.dateTo) count++;
    if (this.filters.scansFilter !== 'all') count++;
    if (this.filters.sortBy !== 'created_at' || this.filters.sortOrder !== 'desc') count++;
    this.activeFiltersCount = count;
  }

  /**
   * Cargar estadísticas
   */
  loadStats(): void {
    this.docqrService.getStats().subscribe({
      next: (response) => {
        if (response.success) {
          this.stats = {
            total: response.data.total_documents,
            totalScans: response.data.total_scans,
            errors: 0, // TODO: calcular errores
            pending: response.data.pending_documents
          };
        }
      },
      error: (error) => {
        console.error('Error al cargar estadísticas:', error);
      }
    });
  }

  /**
   * Buscar documentos
   */
  onSearch(): void {
    this.currentPage = 1;
    this.loadDocuments();
  }

  /**
   * Toggle panel de filtros
   */
  onFilter(): void {
    this.showFilters = !this.showFilters;
  }

  /**
   * Aplicar filtros
   */
  applyFilters(): void {
    this.currentPage = 1;
    this.loadDocuments();
    this.showFilters = false;
  }

  /**
   * Limpiar todos los filtros
   */
  clearFilters(): void {
    this.filters = {
      type: 'all',
      status: 'all',
      dateFrom: '',
      dateTo: '',
      scansFilter: 'all',
      sortBy: 'created_at',
      sortOrder: 'desc'
    };
    this.currentPage = 1;
    this.loadDocuments();
  }

  /**
   * Limpiar un filtro específico
   */
  clearFilter(filterName: keyof typeof this.filters): void {
    if (filterName === 'type') {
      this.filters.type = 'all';
    } else if (filterName === 'status') {
      this.filters.status = 'all';
    } else if (filterName === 'scansFilter') {
      this.filters.scansFilter = 'all';
    } else if (filterName === 'dateFrom') {
      this.filters.dateFrom = '';
    } else if (filterName === 'dateTo') {
      this.filters.dateTo = '';
    } else if (filterName === 'sortBy') {
      this.filters.sortBy = 'created_at';
      this.filters.sortOrder = 'desc';
    }
    this.currentPage = 1;
    this.loadDocuments();
  }

  /**
   * Ver documento
   */
  viewDocument(document: Document): void {
    // Priorizar PDF final con QR, luego PDF original, luego URL del QR
    if (document.final_pdf_url) {
      window.open(document.final_pdf_url, '_blank');
    } else if (document.pdf_url) {
      window.open(document.pdf_url, '_blank');
    } else if (document.qr_url) {
      window.open(document.qr_url, '_blank');
    } else {
      this.notificationService.showWarning('El documento aún no tiene PDF disponible');
    }
  }

  /**
   * Editar documento (ir al editor)
   */
  editDocument(document: Document): void {
    this.router.navigate(['/editor', document.qr_id]);
  }

  /**
   * Copiar enlace del documento
   */
  copyLink(document: Document): void {
    const url = document.qr_url || '';
    
    if (!url) {
      this.notificationService.showError('No hay enlace disponible para copiar');
      return;
    }

    // Intentar usar la API moderna del portapapeles
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(() => {
        this.notificationService.showSuccess('Enlace copiado al portapapeles');
      }).catch((error) => {
        console.error('Error al copiar enlace:', error);
        // Fallback: método antiguo
        this.fallbackCopyToClipboard(url);
      });
    } else {
      // Fallback para navegadores antiguos
      this.fallbackCopyToClipboard(url);
    }
  }

  /**
   * Método alternativo para copiar al portapapeles (fallback)
   */
  private fallbackCopyToClipboard(text: string): void {
    // Usar window.document para evitar conflictos
    const textArea = window.document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    window.document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
      const successful = window.document.execCommand('copy');
      if (successful) {
        this.notificationService.showSuccess('Enlace copiado al portapapeles');
      } else {
        this.notificationService.showError('No se pudo copiar el enlace. Cópialo manualmente: ' + text);
      }
    } catch (error) {
      console.error('Error al copiar enlace:', error);
      this.notificationService.showError('No se pudo copiar el enlace. Cópialo manualmente: ' + text);
    } finally {
      window.document.body.removeChild(textArea);
    }
  }

  /**
   * Descargar documento PDF
   */
  downloadDocument(doc: Document): void {
    // Priorizar PDF final con QR, luego PDF original
    const pdfUrl = doc.final_pdf_url || doc.pdf_url;
    
    if (!pdfUrl) {
      this.notificationService.showWarning('No hay PDF disponible para descargar');
      return;
    }

    try {
      // Crear un enlace temporal para descargar
      // Usar window.document para evitar conflicto con el parámetro 'doc'
      const link = window.document.createElement('a');
      link.href = pdfUrl;
      link.download = doc.original_filename || 'documento.pdf';
      link.target = '_blank';
      window.document.body.appendChild(link);
      link.click();
      window.document.body.removeChild(link);
      
      this.notificationService.showSuccess('PDF descargado exitosamente');
    } catch (error) {
      console.error('Error al descargar PDF:', error);
      this.notificationService.showError('Error al descargar el PDF. Intenta abrir la URL manualmente.');
      // Fallback: abrir en nueva pestaña
      window.open(pdfUrl, '_blank');
    }
  }

  /**
   * Eliminar documento
   */
  deleteDocument(document: Document): void {
    if (!confirm(`¿Estás seguro de que deseas eliminar el documento "${document.original_filename}"?`)) {
      return;
    }

    this.docqrService.deleteDocument(document.id).subscribe({
      next: (response) => {
        if (response.success) {
          this.notificationService.showSuccess('Documento eliminado exitosamente');
          this.loadDocuments();
          this.loadStats();
        } else {
          this.notificationService.showError(response.message || 'Error al eliminar documento');
        }
      },
      error: (error) => {
        console.error('Error al eliminar documento:', error);
        this.notificationService.showError('Error al eliminar documento');
      }
    });
  }

  /**
   * Cambiar página
   */
  changePage(page: number): void {
    if (page >= 1 && page <= this.totalPages) {
      this.currentPage = page;
      this.loadDocuments();
    }
  }

  /**
   * Formatear fecha
   */
  formatDate(date: string): string {
    return new Date(date).toLocaleDateString('es-ES');
  }

  /**
   * Formatear tamaño de archivo
   */
  formatFileSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
  }

  /**
   * Obtener tipo de documento desde folder_name
   */
  getDocumentType(folderName: string): string {
    if (folderName.startsWith('CE')) return 'Certificado';
    if (folderName.startsWith('IN')) return 'Informe de Ensayo';
    if (folderName.startsWith('SU')) return 'Suplemento';
    return 'Otro';
  }

  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }
}

