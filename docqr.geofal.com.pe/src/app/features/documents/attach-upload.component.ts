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
  qrImageUrlWithCache: string = '';
  isSaving: boolean = false; // Estado para el botón "Guardar y Finalizar"

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

    // Validar tamaño (máximo 500MB para PDFs grandes)
    const maxSize = 500 * 1024 * 1024; // 500MB
    if (file.size > maxSize) {
      this.notificationService.showError(`El archivo debe ser menor a ${(maxSize / (1024 * 1024)).toFixed(0)}MB`);
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
        this.isUploading = false;

        if (response.success) {
          this.notificationService.showSuccess('✅ PDF adjuntado exitosamente');
          
          // Limpiar archivo seleccionado y progreso
          this.selectedFile = null;
          this.uploadProgress = 0;
          
          // Recargar carpeta para ver el PDF actualizado
          this.loadDocument();
          
          // Actualizar URL del QR después de subir PDF
          setTimeout(() => {
            this.updateQrImageUrl();
          }, 100);
          
          // Mostrar mensaje para guardar cambios
          setTimeout(() => {
            this.notificationService.showSuccess('💾 Recuerda hacer clic en "Guardar y Finalizar" para completar el proceso');
          }, 2000);
        } else {
          const errorMessage = response.message || 'Error al subir el archivo';
          this.notificationService.showError(errorMessage);
        }
      },
      error: (error) => {
        clearInterval(progressInterval);
        this.isUploading = false;
        this.uploadProgress = 0;
        
        let errorMessage = 'Error al subir el archivo';
        
        if (error.error) {
          if (error.error.message) {
            errorMessage = error.error.message;
          } else if (error.error.errors) {
            // Si hay errores de validación, mostrar el primero
            const firstError = Object.values(error.error.errors)[0];
            if (Array.isArray(firstError) && firstError.length > 0) {
              errorMessage = firstError[0];
            } else if (typeof firstError === 'string') {
              errorMessage = firstError;
            }
          }
        } else if (error.message) {
          errorMessage = error.message;
        }
        
        // Mensajes más específicos según el código de estado
        if (error.status === 422) {
          if (!errorMessage || errorMessage === 'Error al subir el archivo') {
            errorMessage = 'Error de validación. Verifica que el archivo sea un PDF válido y no exceda 500MB.';
          }
        } else if (error.status === 413) {
          errorMessage = 'El archivo es demasiado grande. El servidor no puede procesarlo. Verifica la configuración de PHP.';
        } else if (error.status === 0) {
          errorMessage = 'No se pudo conectar con el servidor. Verifica tu conexión.';
        } else if (error.status === 500) {
          errorMessage = 'Error en el servidor. Por favor, intenta nuevamente o contacta al administrador.';
        }
        
        console.error('Error al adjuntar PDF:', {
          status: error.status,
          message: errorMessage,
          error: error.error
        });
        
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
   * Copiar imagen del QR al portapapeles
   */
  copyQrUrl(): void {
    if (!this.document?.qr_image_url) return;

    // Obtener la imagen del QR y copiarla al portapapeles
    fetch(this.qrImageUrlWithCache || this.document.qr_image_url)
      .then(response => response.blob())
      .then(blob => {
        const item = new ClipboardItem({ 'image/png': blob });
        navigator.clipboard.write([item]).then(() => {
          this.notificationService.showSuccess('Imagen del QR copiada al portapapeles');
        }).catch(() => {
          this.notificationService.showError('Error al copiar la imagen del QR');
        });
      })
      .catch(() => {
        this.notificationService.showError('Error al obtener la imagen del QR');
      });
  }

  /**
   * Descargar imagen del QR con resolución seleccionada
   */
  downloadQr(resolution: 'original' | 'hd' = 'original'): void {
    if (!this.document?.qr_id) return;

    // Construir URL con parámetro de resolución
    const baseUrl = this.document.qr_image_url || `/api/files/qr/${this.document.qr_id}`;
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set('resolution', resolution);
    url.searchParams.set('download', 'true');
    
    const filename = resolution === 'hd' 
      ? `qr-${this.document.qr_id}-1024x1024.png`
      : `qr-${this.document.qr_id}.png`;

    fetch(url.toString())
      .then(response => {
        if (!response.ok) throw new Error('Error al descargar QR');
        return response.blob();
      })
      .then(blob => {
        const downloadUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(downloadUrl);
        this.notificationService.showSuccess(
          resolution === 'hd' 
            ? 'QR en alta resolución descargado exitosamente' 
            : 'QR descargado exitosamente'
        );
      })
      .catch(() => {
        this.notificationService.showError('Error al descargar el QR');
      });
  }

  /**
   * Copiar URL del QR al portapapeles
   */
  copyQrUrlToClipboard(): void {
    if (!this.document?.qr_url) {
      this.notificationService.showError('No hay URL disponible para copiar');
      return;
    }

    const urlToCopy = this.document.qr_url;
    navigator.clipboard.writeText(urlToCopy).then(() => {
      this.notificationService.showSuccess('URL copiada al portapapeles');
    }).catch(() => {
      // Fallback para navegadores antiguos
      const textArea = document.createElement('textarea');
      textArea.value = urlToCopy;
      textArea.style.position = 'fixed';
      textArea.style.left = '-999999px';
      textArea.style.top = '-999999px';
      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();
      
      try {
        const successful = document.execCommand('copy');
        if (successful) {
          this.notificationService.showSuccess('URL copiada al portapapeles');
        } else {
          this.notificationService.showError('Error al copiar la URL');
        }
      } catch {
        this.notificationService.showError('Error al copiar la URL');
      } finally {
        document.body.removeChild(textArea);
      }
    });
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
   * Guardar y finalizar - Volver a la lista de documentos
   */
  saveAndFinish(): void {
    if (!this.document || !this.document.pdf_url) {
      this.notificationService.showWarning('Debes adjuntar un PDF primero');
      return;
    }

    this.isSaving = true;

    // Mostrar mensaje de éxito
    this.notificationService.showSuccess('✅ Documento guardado exitosamente');
    
    // Navegar a la lista de documentos
    setTimeout(() => {
      this.router.navigate(['/documents']);
    }, 500);
  }

  /**
   * Cancelar y volver a la lista sin guardar
   */
  cancelAndReturn(): void {
    if (this.document && !this.document.pdf_url) {
      // Si no se adjuntó PDF, advertir
      if (confirm('¿Estás seguro? El documento quedará sin PDF adjunto.')) {
        this.router.navigate(['/documents']);
      }
    } else {
      this.router.navigate(['/documents']);
    }
  }
}

