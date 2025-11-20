import { Component, OnInit, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { DocqrService, UploadResponse } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';

/**
 * Componente para subir documentos PDF y generar códigos QR
 */
@Component({
  selector: 'app-upload',
  standalone: true,
  imports: [CommonModule, FormsModule, HeaderComponent, SidebarComponent],
  templateUrl: './upload.component.html',
  styleUrls: ['./upload.component.scss']
})
export class UploadComponent implements OnInit {
  @ViewChild('fileInput') fileInput!: ElementRef<HTMLInputElement>;
  
  selectedFile: File | null = null;
  documentType: string = '';
  folderName: string = ''; // Solo la parte editable (sin las siglas)
  isUploading: boolean = false;
  uploadProgress: number = 0;
  dragOver: boolean = false;
  sidebarOpen: boolean = false;

  // Tipos de documentos con sus siglas
  documentTypes = [
    { value: 'CE', label: 'Certificado' },
    { value: 'IN', label: 'Informe de Ensayo' },
    { value: 'SU', label: 'Suplemento' }
  ];

  /**
   * Obtener las siglas del tipo de documento seleccionado
   */
  getDocumentTypePrefix(): string {
    return this.documentType || '';
  }

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService,
    private router: Router
  ) {}

  ngOnInit(): void {
    // En desktop, el sidebar siempre está visible (manejado por CSS)
    // En móvil, inicia cerrado
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
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
      const file = files[0];
      // Verificar que sea un archivo válido
      if (file && file instanceof File) {
        this.handleFile(file);
      } else {
        this.notificationService.showError('Archivo no válido. Por favor, intenta con otro archivo.');
      }
    } else {
      this.notificationService.showError('No se detectó ningún archivo. Por favor, intenta nuevamente.');
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
    // Validar tipo de archivo (por MIME type o extensión)
    const isValidPdf = file.type === 'application/pdf' || 
                       file.type === 'application/x-pdf' ||
                       file.name.toLowerCase().endsWith('.pdf');
    
    if (!isValidPdf) {
      this.notificationService.showError('Solo se permiten archivos PDF');
      return;
    }

    // Validar tamaño (máximo 500MB para drag and drop)
    const maxSize = 500 * 1024 * 1024; // 500MB
    if (file.size > maxSize) {
      this.notificationService.showError(`El archivo debe ser menor a ${(maxSize / (1024 * 1024)).toFixed(0)}MB`);
      return;
    }

    // Validar que el archivo no esté vacío
    if (file.size === 0) {
      this.notificationService.showError('El archivo está vacío');
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
   * Manejar cambio de tipo de documento
   * Solo limpia el campo editable si estaba vacío
   */
  onDocumentTypeChange(): void {
  }

  /**
   * Validar formulario
   */
  isFormValid(): boolean {
    return !!(
      this.selectedFile &&
      this.documentType &&
      this.folderName.trim()
    );
  }

  /**
   * Subir archivo
   */
  uploadFile(): void {
    // Validaciones
    if (!this.selectedFile) {
      this.notificationService.showError('Por favor selecciona un archivo PDF');
      return;
    }

    if (!this.documentType) {
      this.notificationService.showError('Por favor selecciona un tipo de documento');
      return;
    }

    if (!this.folderName.trim()) {
      this.notificationService.showError('Por favor ingresa el código del documento');
      return;
    }

    // Validar formato del código (solo alfanumérico y guiones)
    const codePattern = /^[a-zA-Z0-9\-]+$/;
    if (!codePattern.test(this.folderName.trim())) {
      this.notificationService.showError('El código solo puede contener letras, números y guiones');
      return;
    }

    // Combinar las siglas con el nombre de carpeta editable
    const fullFolderName = this.documentType + (this.folderName.trim() ? '-' + this.folderName.trim() : '');

    this.isUploading = true;
    this.uploadProgress = 0;

    const progressInterval = setInterval(() => {
      if (this.uploadProgress < 90) {
        this.uploadProgress += 10;
      }
    }, 200);

    this.docqrService.uploadPdf(this.selectedFile, fullFolderName).subscribe({
      next: (response: UploadResponse) => {
        clearInterval(progressInterval);
        this.uploadProgress = 100;

        if (response.success) {
          this.notificationService.showSuccess('PDF subido y QR generado exitosamente');
          
          // Redirigir al editor después de 1 segundo
          setTimeout(() => {
            this.router.navigate(['/editor', response.data.qr_id]);
          }, 1000);
        }
      },
      error: (error) => {
        clearInterval(progressInterval);
        this.isUploading = false;
        this.uploadProgress = 0;
        
        let errorMessage = 'Error al subir el archivo';
        
        if (error.error?.message) {
          errorMessage = error.error.message;
        } else if (error.message) {
          errorMessage = error.message;
        } else if (error.status === 0) {
          errorMessage = 'No se pudo conectar con el servidor. Verifica tu conexión.';
        } else if (error.status === 422) {
          errorMessage = error.error?.message || 'Error de validación. Verifica que el PDF sea válido y tenga solo 1 página.';
        } else if (error.status === 500) {
          errorMessage = 'Error en el servidor. Por favor, intenta nuevamente.';
        }
        
        this.notificationService.showError(errorMessage);
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
}

