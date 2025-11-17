import { Component, Input, Output, EventEmitter, OnInit, OnDestroy, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { NgxExtendedPdfViewerModule } from 'ngx-extended-pdf-viewer';

/**
 * Componente modal para vista previa de PDF
 */
@Component({
  selector: 'app-pdf-preview-modal',
  standalone: true,
  imports: [CommonModule, NgxExtendedPdfViewerModule],
  templateUrl: './pdf-preview-modal.component.html',
  styleUrls: ['./pdf-preview-modal.component.scss']
})
export class PdfPreviewModalComponent implements OnInit, OnDestroy, OnChanges {
  @Input() pdfUrl: string = '';
  @Input() documentName: string = '';
  @Input() isOpen: boolean = false;
  @Output() close = new EventEmitter<void>();

  zoomLevel: number = 100;
  currentPage: number = 1;
  totalPages: number = 1;
  darkMode: boolean = false;
  
  // Referencia al viewer para controlar zoom y navegación
  pdfViewer: any;
  
  // URL sanitizada para el iframe
  safePdfUrl: SafeResourceUrl | null = null;

  constructor(private sanitizer: DomSanitizer) {}

  ngOnInit(): void {
    this.updateBodyScroll();
    this.updateSafeUrl(); // Sanitizar URL al inicializar
  }

  ngOnDestroy(): void {
    // Restaurar scroll del body
    document.body.style.overflow = '';
  }

  /**
   * Actualizar scroll del body según el estado del modal
   */
  private updateBodyScroll(): void {
    if (this.isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
  }

  /**
   * Watch para cambios en isOpen
   */
  ngOnChanges(changes: SimpleChanges): void {
    if (changes['isOpen']) {
      this.updateBodyScroll();
    }
    if (changes['pdfUrl']) {
      // Sanitizar la URL cuando cambia
      this.updateSafeUrl();
    }
  }

  /**
   * Actualizar URL sanitizada
   */
  private updateSafeUrl(): void {
    if (this.pdfUrl) {
      const urlWithParams = this.getPdfUrlWithParams();
      this.safePdfUrl = this.sanitizer.bypassSecurityTrustResourceUrl(urlWithParams);
    } else {
      this.safePdfUrl = null;
    }
  }

  /**
   * Cerrar modal
   */
  closeModal(): void {
    this.isOpen = false;
    document.body.style.overflow = '';
    this.close.emit();
  }

  /**
   * Manejar clic fuera del modal
   */
  onBackdropClick(event: MouseEvent): void {
    if ((event.target as HTMLElement).classList.contains('modal-backdrop')) {
      this.closeModal();
    }
  }

  /**
   * Zoom in
   */
  zoomIn(): void {
    if (this.zoomLevel < 200) {
      this.zoomLevel += 10;
      this.updateIframeZoom();
    }
  }

  /**
   * Zoom out
   */
  zoomOut(): void {
    if (this.zoomLevel > 50) {
      this.zoomLevel -= 10;
      this.updateIframeZoom();
    }
  }

  /**
   * Reset zoom
   */
  resetZoom(): void {
    this.zoomLevel = 100;
    this.updateIframeZoom();
  }

  /**
   * Fit to page
   */
  fitToPage(): void {
    this.zoomLevel = 100;
    this.updateIframeZoom();
  }

  /**
   * Actualizar zoom del iframe
   */
  private updateIframeZoom(): void {
    // Actualizar la URL sanitizada con el nuevo zoom
    this.updateSafeUrl();
  }

  /**
   * Página anterior
   */
  previousPage(): void {
    if (this.currentPage > 1) {
      this.currentPage--;
      this.updateIframePage();
    }
  }

  /**
   * Página siguiente
   */
  nextPage(): void {
    if (this.currentPage < this.totalPages) {
      this.currentPage++;
      this.updateIframePage();
    }
  }

  /**
   * Actualizar página del iframe
   */
  private updateIframePage(): void {
    // Actualizar la URL sanitizada con la nueva página
    this.updateSafeUrl();
  }

  /**
   * Toggle modo oscuro
   */
  toggleDarkMode(): void {
    this.darkMode = !this.darkMode;
  }

  /**
   * Descargar PDF
   */
  downloadPdf(): void {
    if (this.pdfUrl) {
      const link = document.createElement('a');
      link.href = this.pdfUrl;
      link.download = this.documentName || 'documento.pdf';
      link.click();
    }
  }

  /**
   * Imprimir PDF
   */
  printPdf(): void {
    if (this.pdfUrl) {
      window.open(this.pdfUrl, '_blank');
      setTimeout(() => {
        window.print();
      }, 500);
    }
  }

  /**
   * Callback cuando se carga el PDF
   * Nota: Con iframe no podemos obtener el total de páginas fácilmente
   * Por ahora, estableceremos un valor por defecto o intentaremos obtenerlo
   */
  onPdfLoadComplete(event: any): void {
    // El evento puede venir como número o como objeto
    if (typeof event === 'number') {
      this.totalPages = event;
    } else if (event && event.pagesCount) {
      this.totalPages = event.pagesCount;
    } else if (event && typeof event === 'object' && 'pagesCount' in event) {
      this.totalPages = (event as any).pagesCount;
    } else {
      // Con iframe, establecer un valor por defecto
      // El usuario puede navegar y el contador se actualizará manualmente
      this.totalPages = this.totalPages || 1;
    }
  }

  /**
   * Callback cuando cambia la página
   */
  onPageChange(event: any): void {
    // El evento pageChange devuelve directamente el número de página
    if (typeof event === 'number') {
      this.currentPage = event;
    } else if (event && event.pageNumber) {
      this.currentPage = event.pageNumber;
    } else if (event && typeof event === 'object' && 'pageNumber' in event) {
      this.currentPage = (event as any).pageNumber;
    }
  }

  /**
   * Obtener URL del PDF con parámetros para iframe
   */
  getPdfUrlWithParams(): string {
    if (!this.pdfUrl) {
      return '';
    }
    // Agregar parámetros de página y zoom (pueden no funcionar en todos los navegadores)
    const separator = this.pdfUrl.includes('?') ? '&' : '#';
    return `${this.pdfUrl}${separator}page=${this.currentPage}&zoom=${this.zoomLevel}`;
  }
}

