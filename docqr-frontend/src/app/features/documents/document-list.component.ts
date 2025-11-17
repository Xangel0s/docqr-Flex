import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule, NavigationEnd } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { Subscription, filter } from 'rxjs';
import { DocqrService, Document, DocumentsResponse } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { PdfPreviewModalComponent } from '../../shared/components/pdf-preview-modal/pdf-preview-modal.component';
import { DeleteConfirmModalComponent } from '../../shared/components/delete-confirm-modal/delete-confirm-modal.component';
import { EditOptionModalComponent, EditType } from '../../shared/components/edit-option-modal/edit-option-modal.component';
import { EditFolderModalComponent } from '../../shared/components/edit-folder-modal/edit-folder-modal.component';

/**
 * Componente para listar documentos
 */
@Component({
  selector: 'app-document-list',
  standalone: true,
  imports: [CommonModule, RouterModule, FormsModule, HeaderComponent, SidebarComponent, PdfPreviewModalComponent, DeleteConfirmModalComponent, EditOptionModalComponent, EditFolderModalComponent],
  templateUrl: './document-list.component.html',
  styleUrls: ['./document-list.component.scss']
})
export class DocumentListComponent implements OnInit, OnDestroy {
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

  // Modal de vista previa
  previewModalOpen: boolean = false;
  previewPdfUrl: string = '';
  previewDocumentName: string = '';

  // Modal de confirmación de eliminación
  deleteModalOpen: boolean = false;
  documentToDelete: Document | null = null;

  // Modal de selección de tipo de edición
  editOptionModalOpen: boolean = false;
  documentToEdit: Document | null = null;

  // Modal de edición de nombre de carpeta
  editFolderModalOpen: boolean = false;
  savingFolderName: boolean = false;

  // Estado de exportación CSV
  exportingCSV: boolean = false;

  // Suscripción para detectar navegación
  private routerSubscription?: Subscription;
  
  // Caché para evitar recargas innecesarias
  private lastLoadTime: number = 0;
  private readonly CACHE_DURATION = 5000; // 5 segundos

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
    
    // Recargar documentos cuando vuelves del editor
    this.routerSubscription = this.router.events
      .pipe(filter(event => event instanceof NavigationEnd))
      .subscribe((event: any) => {
        // Si volvemos a la lista desde otra ruta, recargar documentos solo si es necesario
        if (event.url === '/documents' || event.urlAfterRedirects === '/documents') {
          this.loadDocuments(false); // No forzar, usar caché si está disponible
        }
      });
  }

  ngOnDestroy(): void {
    if (this.routerSubscription) {
      this.routerSubscription.unsubscribe();
    }
  }

  /**
   * Cargar documentos (con caché para evitar recargas innecesarias)
   */
  loadDocuments(force: boolean = false): void {
    // Verificar caché: si se cargó hace menos de 5 segundos y no es forzado, no recargar
    const now = Date.now();
    if (!force && (now - this.lastLoadTime) < this.CACHE_DURATION && this.documents.length > 0) {
      return;
    }
    
    this.loading = true;
    this.updateActiveFiltersCount();
    
    this.docqrService.getDocuments(undefined, this.searchTerm, this.currentPage, this.filters).subscribe({
      next: (response: DocumentsResponse) => {
        if (response.success) {
          this.documents = response.data;
          this.currentPage = response.meta.current_page;
          this.totalPages = response.meta.last_page;
          this.totalDocuments = response.meta.total;
          this.lastLoadTime = Date.now(); // Actualizar tiempo de carga
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
   * Ver documento (abrir modal de vista previa)
   */
  viewDocument(document: Document): void {
    // Priorizar PDF final con QR, luego PDF original
    if (document.final_pdf_url) {
      this.previewPdfUrl = document.final_pdf_url;
      this.previewDocumentName = document.original_filename;
      this.previewModalOpen = true;
    } else if (document.pdf_url) {
      this.previewPdfUrl = document.pdf_url;
      this.previewDocumentName = document.original_filename;
      this.previewModalOpen = true;
    } else {
      this.notificationService.showWarning('El documento aún no tiene PDF disponible');
    }
  }

  /**
   * Cerrar modal de vista previa
   */
  closePreviewModal(): void {
    this.previewModalOpen = false;
    this.previewPdfUrl = '';
    this.previewDocumentName = '';
  }

  /**
   * Abrir modal de selección de tipo de edición
   */
  editDocument(document: Document): void {
    this.documentToEdit = document;
    this.editOptionModalOpen = true;
  }

  /**
   * Cerrar modal de selección de tipo de edición
   */
  closeEditOptionModal(): void {
    this.editOptionModalOpen = false;
    // NO poner documentToEdit en null aquí, se necesita para los modales siguientes
  }

  /**
   * Manejar selección de tipo de edición
   */
  onEditOptionSelected(editType: EditType): void {
    // Guardar referencia del documento antes de cerrar el modal
    const documentToEdit = this.documentToEdit;
    
    if (!documentToEdit) {
      this.notificationService.showError('No hay documento seleccionado');
      this.editOptionModalOpen = false;
      return;
    }
    
    // Cerrar modal de selección
    this.editOptionModalOpen = false;
    
    if (editType === 'qr_position') {
      // Abrir editor de posición del QR
      this.router.navigate(['/editor', documentToEdit.qr_id]);
      // Limpiar después de navegar
      this.documentToEdit = null;
    } else if (editType === 'folder') {
      // Abrir modal de edición de nombre de carpeta
      // documentToEdit se mantiene para el modal de carpeta
      this.openEditFolderModal();
    }
  }

  /**
   * Abrir modal de edición de nombre de carpeta
   */
  openEditFolderModal(): void {
    this.editFolderModalOpen = true;
  }

  /**
   * Cerrar modal de edición de nombre de carpeta
   */
  closeEditFolderModal(): void {
    this.editFolderModalOpen = false;
    // Limpiar documento solo después de cerrar el modal
    this.documentToEdit = null;
  }

  /**
   * Guardar nuevo nombre de carpeta
   */
  saveFolderName(newFolderName: string): void {
    if (!this.documentToEdit) {
      this.notificationService.showError('No hay documento seleccionado');
      return;
    }

    if (!newFolderName || newFolderName.trim() === '') {
      this.notificationService.showError('El nombre de carpeta no puede estar vacío');
      return;
    }

    this.savingFolderName = true;

    this.docqrService.updateFolderName(this.documentToEdit.qr_id, newFolderName).subscribe({
      next: (response) => {
        if (response.success) {
          this.notificationService.showSuccess(`✅ Nombre de carpeta actualizado exitosamente a: ${newFolderName}`);
          this.closeEditFolderModal();
          this.loadDocuments(true); // Forzar recarga para mostrar cambios
          this.loadStats(); // Actualizar estadísticas
        } else {
          this.notificationService.showError(response.message || 'Error al actualizar el nombre de carpeta');
        }
        this.savingFolderName = false;
      },
      error: (error) => {
        const errorMessage = error.error?.message || error.error?.errors?.folder_name?.[0] || 'Error al actualizar el nombre de carpeta';
        this.notificationService.showError(errorMessage);
        this.savingFolderName = false;
      }
    });
  }

  /**
   * Copiar enlace del documento
   * Copia automáticamente la URL del documento y muestra notificación
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
        this.notificationService.showSuccess('✅ Enlace copiado al portapapeles');
      }).catch(() => {
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
        this.notificationService.showSuccess('✅ Enlace copiado al portapapeles');
      } else {
        this.notificationService.showError('No se pudo copiar el enlace. Cópialo manualmente');
      }
    } catch {
      this.notificationService.showError('No se pudo copiar el enlace. Cópialo manualmente');
    } finally {
      window.document.body.removeChild(textArea);
    }
  }

  /**
   * Descargar documento PDF
   * Obtiene el documento actualizado del backend para asegurar la URL más reciente
   */
  downloadDocument(doc: Document): void {
    // Obtener el documento actualizado del backend para asegurar que tenemos la URL más reciente
    this.docqrService.getDocumentByQrId(doc.qr_id).subscribe({
      next: (response) => {
        if (response.success && response.data) {
          const updatedDoc = response.data;
          // Usar el documento actualizado del backend
          const pdfUrl = updatedDoc.final_pdf_url || updatedDoc.pdf_url;
          
          if (!pdfUrl) {
            this.notificationService.showWarning('No hay PDF disponible para descargar');
            return;
          }

          try {
            // Agregar timestamp para evitar caché del navegador
            const urlWithCacheBuster = `${pdfUrl}?t=${Date.now()}`;
            
            // Usar fetch con blob para forzar la descarga (no abrir pestaña, no cambiar de página)
            fetch(urlWithCacheBuster)
              .then(response => {
                if (!response.ok) {
                  throw new Error('Error al obtener el PDF');
                }
                return response.blob();
              })
              .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const link = window.document.createElement('a');
                link.href = url;
                link.download = updatedDoc.original_filename || 'documento.pdf';
                link.style.display = 'none';
                window.document.body.appendChild(link);
                link.click();
                window.document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
                
                // Actualizar el documento en la lista local
                const index = this.documents.findIndex(d => d.id === doc.id);
                if (index !== -1) {
                  this.documents[index] = updatedDoc;
                }
                
                this.notificationService.showSuccess('✅ PDF descargado exitosamente');
              })
              .catch(error => {
                console.error('Error al descargar PDF:', error);
                this.notificationService.showError('Error al descargar el PDF');
              });
          } catch (error) {
            console.error('Error al descargar PDF:', error);
            this.notificationService.showError('Error al descargar el PDF');
          }
        } else {
          // Fallback: usar el documento de la lista
          const pdfUrl = doc.final_pdf_url || doc.pdf_url;
          if (!pdfUrl) {
            this.notificationService.showWarning('No hay PDF disponible para descargar');
            return;
          }
          
          // Usar fetch con blob para forzar la descarga
          const urlWithCacheBuster = `${pdfUrl}?t=${Date.now()}`;
          fetch(urlWithCacheBuster)
            .then(response => {
              if (!response.ok) {
                throw new Error('Error al obtener el PDF');
              }
              return response.blob();
            })
            .then(blob => {
              const url = window.URL.createObjectURL(blob);
              const link = window.document.createElement('a');
              link.href = url;
              link.download = doc.original_filename || 'documento.pdf';
              link.style.display = 'none';
              window.document.body.appendChild(link);
              link.click();
              window.document.body.removeChild(link);
              window.URL.revokeObjectURL(url);
              this.notificationService.showSuccess('✅ PDF descargado exitosamente');
            })
            .catch(error => {
              console.error('Error al descargar PDF:', error);
              this.notificationService.showError('Error al descargar el PDF');
            });
        }
      },
      error: (error) => {
        console.error('Error al obtener documento actualizado:', error);
        // Fallback: usar el documento de la lista con cache buster
        const pdfUrl = doc.final_pdf_url || doc.pdf_url;
        if (!pdfUrl) {
          this.notificationService.showWarning('No hay PDF disponible para descargar');
          return;
        }
        
        // Usar fetch con blob para forzar la descarga
        const urlWithCacheBuster = `${pdfUrl}?t=${Date.now()}`;
        fetch(urlWithCacheBuster)
          .then(response => {
            if (!response.ok) {
              throw new Error('Error al obtener el PDF');
            }
            return response.blob();
          })
          .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const link = window.document.createElement('a');
            link.href = url;
            link.download = doc.original_filename || 'documento.pdf';
            link.style.display = 'none';
            window.document.body.appendChild(link);
            link.click();
            window.document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
            this.notificationService.showSuccess('✅ PDF descargado exitosamente');
          })
          .catch(error => {
            console.error('Error al descargar PDF:', error);
            this.notificationService.showError('Error al descargar el PDF');
          });
      }
    });
  }

  /**
   * Abrir modal de confirmación de eliminación
   */
  openDeleteModal(document: Document): void {
    this.documentToDelete = document;
    this.deleteModalOpen = true;
  }

  /**
   * Cerrar modal de confirmación de eliminación
   */
  closeDeleteModal(): void {
    this.deleteModalOpen = false;
    this.documentToDelete = null;
  }

  /**
   * Confirmar eliminación de documento
   */
  confirmDelete(): void {
    if (!this.documentToDelete) {
      return;
    }

    const documentId = this.documentToDelete.id;
    const documentName = this.documentToDelete.original_filename;

    this.docqrService.deleteDocument(documentId).subscribe({
      next: (response) => {
        if (response.success) {
          this.notificationService.showSuccess(`✅ Documento "${documentName}" eliminado exitosamente`);
          this.closeDeleteModal();
          this.loadDocuments(true); // Forzar recarga después de eliminar
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

  /**
   * Exportar todos los documentos a CSV
   */
  exportToCSV(): void {
    if (this.exportingCSV) {
      return; // Evitar múltiples clics
    }

    this.exportingCSV = true;
    this.notificationService.showInfo('Generando archivo CSV... Esto puede tomar unos momentos.');
    
    // Obtener TODOS los documentos sin paginación para exportar
    this.docqrService.getAllDocumentsForExport(this.searchTerm, this.filters).subscribe({
      next: (response: DocumentsResponse) => {
        if (response.success && response.data.length > 0) {
          try {
            this.generateCSV(response.data);
            this.notificationService.showSuccess(`✅ CSV exportado exitosamente con ${response.data.length} documentos`);
          } catch (error) {
            console.error('Error al generar CSV:', error);
            this.notificationService.showError('Error al generar el archivo CSV');
          } finally {
            this.exportingCSV = false;
          }
        } else {
          this.notificationService.showWarning('No hay documentos para exportar');
          this.exportingCSV = false;
        }
      },
      error: (error) => {
        console.error('Error al obtener documentos para exportar:', error);
        this.notificationService.showError('Error al generar el archivo CSV');
        this.exportingCSV = false;
      }
    });
  }

  /**
   * Generar archivo CSV con todos los datos
   * Usa punto y coma (;) como separador para Excel en español
   */
  private generateCSV(documents: Document[]): void {
    // Definir columnas del CSV
    const headers = [
      'ID',
      'QR ID',
      'Nombre de Archivo',
      'Carpeta',
      'Tipo',
      'Tamaño (bytes)',
      'Tamaño (formato)',
      'Estado',
      'Escaneos',
      'Último Escaneo',
      'Fecha de Creación',
      'URL QR',
      'URL PDF',
      'URL PDF Final'
    ];

    // Separador: punto y coma para Excel en español
    const separator = ';';

    // Crear filas de datos
    const rows = documents.map(doc => {
      const tipo = this.getDocumentType(doc.folder_name);
      const fechaCreacion = doc.created_at ? new Date(doc.created_at).toLocaleString('es-ES') : 'N/A';
      const ultimoEscaneo = doc.last_scanned_at ? new Date(doc.last_scanned_at).toLocaleString('es-ES') : 'N/A';
      
      return [
        doc.id.toString(),
        doc.qr_id,
        this.escapeCSV(doc.original_filename, separator),
        this.escapeCSV(doc.folder_name, separator),
        tipo,
        doc.file_size.toString(),
        this.formatFileSize(doc.file_size),
        doc.status,
        doc.scan_count.toString(),
        ultimoEscaneo,
        fechaCreacion,
        doc.qr_url || '',
        doc.pdf_url || '',
        doc.final_pdf_url || ''
      ];
    });

    // Combinar headers y rows con punto y coma
    const csvContent = [
      headers.join(separator),
      ...rows.map(row => row.join(separator))
    ].join('\n');

    // Agregar BOM para UTF-8 (Excel lo reconoce mejor)
    const BOM = '\uFEFF';
    const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' });
    
    // Crear enlace de descarga
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    
    // Nombre del archivo con fecha
    const fecha = new Date().toISOString().split('T')[0];
    link.download = `documentos_geofal_${fecha}.csv`;
    
    // Descargar
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  }

  /**
   * Escapar valores para CSV (manejar punto y coma, comillas, saltos de línea)
   */
  private escapeCSV(value: string, separator: string = ';'): string {
    if (!value) return '';
    
    // Si contiene el separador, comillas o saltos de línea, envolver en comillas y escapar comillas internas
    if (value.includes(separator) || value.includes('"') || value.includes('\n') || value.includes('\r')) {
      return `"${value.replace(/"/g, '""')}"`;
    }
    
    return value;
  }
}

