import { Component, OnInit, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { DocqrService, Document } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';

/**
 * Componente para subir PDF final a la carpeta creada (Paso 2 del flujo "Adjuntar")
 * Muestra el QR y permite subir el PDF sin procesarlo
 */
@Component({
  selector: 'app-attach-upload',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, HeaderComponent, SidebarComponent],
  templateUrl: './attach-upload.component.html',
  styleUrls: ['./attach-upload.component.scss']
})
export class AttachUploadComponent implements OnInit {
  @ViewChild('fileInput') fileInput!: ElementRef<HTMLInputElement>;
  
  sidebarOpen: boolean = false;
  qrId: string = '';
  document: Document | null = null;
  loading: boolean = true;
  selectedFile: File | null = null;
  isUploading: boolean = false;
  uploadProgress: number = 0;
  dragOver: boolean = false;
  qrImageUrlWithCache: string = ''; // URL del QR con cache buster (se actualiza solo cuando es necesario)

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private docqrService: DocqrService,
    private notificationService: NotificationService
  ) {}

  ngOnInit(): void {
    // Obtener qrId de la ruta
    this.qrId = this.route.snapshot.paramMap.get('qrId') || '';
    
    if (!this.qrId) {
      this.notificationService.showError('ID de carpeta no válido');
      this.router.navigate(['/documents']);
      return;
    }

    // Cargar información de la carpeta
    this.loadDocument();

    // En desktop, el sidebar siempre está visible
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
  }

  /**
   * Cargar información de la carpeta
   */
  loadDocument(): void {
    this.loading = true;
    this.docqrService.getDocumentByQrId(this.qrId).subscribe({
      next: (response) => {
        this.loading = false;
        if (response.success && response.data) {
          this.document = response.data;
          // Actualizar URL del QR con cache buster solo cuando se carga el documento
          this.updateQrImageUrl();
        } else {
          this.notificationService.showError('Carpeta no encontrada');
          this.router.navigate(['/documents']);
        }
      },
      error: (error) => {
        this.loading = false;
        const errorMessage = error.error?.message || 'Error al cargar la carpeta';
        this.notificationService.showError(errorMessage);
        this.router.navigate(['/documents']);
      }
    });
  }

  /**
   * Manejar drag over
   */
  onDragOver(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.dragOver = true;
  }

  /**
   * Manejar drag leave
   */
  onDragLeave(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.dragOver = false;
  }

  /**
   * Manejar drop de archivo
   */
  onDrop(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.dragOver = false;

    const files = event.dataTransfer?.files;
    if (files && files.length > 0) {
      this.handleFile(files[0]);
    }
  }

  /**
   * Manejar selección de archivo
   */
  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files.length > 0) {
      this.handleFile(input.files[0]);
    }
  }

  /**
   * Procesar archivo seleccionado
   */
  private handleFile(file: File): void {
    // Validar tipo de archivo
    if (file.type !== 'application/pdf') {
      this.notificationService.showError('Solo se permiten archivos PDF');
      return;
    }

    // Validar tamaño (máximo 50MB para PDFs complejos)
    if (file.size > 50 * 1024 * 1024) {
      this.notificationService.showError('El archivo debe ser menor a 50MB');
      return;
    }

    this.selectedFile = file;
  }

  /**
   * Remover archivo seleccionado
   */
  removeFile(): void {
    this.selectedFile = null;
    this.uploadProgress = 0;
  }

  /**
   * Subir PDF (sin procesar)
   */
  uploadPdf(): void {
    if (!this.selectedFile) {
      this.notificationService.showError('Por favor selecciona un archivo PDF');
      return;
    }

    this.isUploading = true;
    this.uploadProgress = 0;

    // Simular progreso
    const progressInterval = setInterval(() => {
      if (this.uploadProgress < 90) {
        this.uploadProgress += 10;
      }
    }, 200);

    // Subir PDF sin procesar
    this.docqrService.attachPdf(this.qrId, this.selectedFile).subscribe({
      next: (response) => {
        clearInterval(progressInterval);
        this.uploadProgress = 100;
        this.isUploading = false; // CRÍTICO: Detener loading

        if (response.success) {
          this.notificationService.showSuccess('PDF adjuntado exitosamente');
          
          // Limpiar archivo seleccionado y progreso
          this.selectedFile = null;
          this.uploadProgress = 0;
          
          // Recargar carpeta para ver el PDF actualizado
          this.loadDocument();
          // Actualizar URL del QR después de subir PDF
          setTimeout(() => {
            this.updateQrImageUrl();
          }, 100);
        } else {
          const errorMessage = response.message || 'Error al subir el archivo';
          this.notificationService.showError(errorMessage);
        }
      },
      error: (error) => {
        clearInterval(progressInterval);
        this.isUploading = false;
        this.uploadProgress = 0;
        
        const errorMessage = error.error?.message || 'Error al subir el archivo';
        this.notificationService.showError(errorMessage);
      }
    });
  }

  /**
   * Actualizar URL del QR con cache buster
   * Se llama solo cuando es necesario (carga inicial, actualización del documento)
   */
  private updateQrImageUrl(): void {
    if (!this.document?.qr_image_url) {
      this.qrImageUrlWithCache = '';
      return;
    }
    // Agregar timestamp para invalidar caché del navegador
    const separator = this.document.qr_image_url.includes('?') ? '&' : '?';
    this.qrImageUrlWithCache = `${this.document.qr_image_url}${separator}t=${Date.now()}`;
  }

  /**
   * Copiar URL del QR al portapapeles
   */
  copyQrUrl(): void {
    if (!this.document?.qr_url) return;

    navigator.clipboard.writeText(this.document.qr_url).then(() => {
      this.notificationService.showSuccess('URL del QR copiada al portapapeles');
    }).catch(() => {
      this.notificationService.showError('Error al copiar la URL');
    });
  }

  /**
   * Descargar imagen del QR
   */
  downloadQr(): void {
    if (!this.document?.qr_image_url) return;

    const link = document.createElement('a');
    link.href = this.document.qr_image_url;
    link.download = `qr-${this.document.qr_id}.png`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
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

