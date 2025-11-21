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
  
  // Validación de código en tiempo real
  codeExists: boolean = false;
  checkingCode: boolean = false;
  folderNameError: string | null = null;
  private checkCodeTimeout: any = null;

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
   * Verificar si el código existe (con debounce)
   */
  checkCodeExists(): void {
    // Limpiar timeout anterior
    if (this.checkCodeTimeout) {
      clearTimeout(this.checkCodeTimeout);
    }

    // Si el campo está vacío, no verificar y limpiar estado
    if (!this.folderName.trim() || !this.documentType) {
      this.codeExists = false;
      this.folderNameError = null;
      this.checkingCode = false;
      return;
    }

    // Validar formato del código
    const codePattern = /^[a-zA-Z0-9\-]+$/;
    if (!codePattern.test(this.folderName.trim())) {
      this.folderNameError = 'El código solo puede contener letras, números y guiones';
      this.codeExists = false;
      this.checkingCode = false;
      return;
    }

    // IMPORTANTE: Mostrar estado de verificación mientras espera
    // Esto evita el "falso verde" - no mostrar verde hasta verificar
    this.checkingCode = true;
    this.codeExists = false;
    this.folderNameError = null;

    // Debounce de 500ms
    this.checkCodeTimeout = setTimeout(() => {
      const fullFolderName = this.documentType + (this.folderName.trim() ? '-' + this.folderName.trim() : '');
      
      this.docqrService.checkCodeExists(fullFolderName).subscribe({
        next: (response) => {
          this.checkingCode = false;
          this.codeExists = response.exists;
          if (response.exists) {
            this.folderNameError = 'Este código ya existe en el sistema. Por favor elige otro nombre único.';
          } else {
            this.folderNameError = null;
          }
        },
        error: () => {
          this.checkingCode = false;
          // En caso de error, no bloquear el formulario
          this.codeExists = false;
          this.folderNameError = null;
        }
      });
    }, 500);
  }

  /**
   * Manejar cambio de tipo de documento
   * Limpia el estado y valida de nuevo si hay código
   */
  onDocumentTypeChange(): void {
    // Limpiar estado de validación anterior
    this.codeExists = false;
    this.folderNameError = null;
    this.checkingCode = false;
    
    // Si hay código escrito, validar de nuevo con el nuevo tipo
    if (this.folderName.trim()) {
      // Pequeño delay para asegurar que el tipo se actualizó
      setTimeout(() => {
        this.checkCodeExists();
      }, 100);
    }
  }

  /**
   * Validar formulario
   */
  isFormValid(): boolean {
    return !!(
      this.selectedFile &&
      this.documentType &&
      this.folderName.trim() &&
      !this.codeExists &&
      !this.folderNameError &&
      !this.checkingCode
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
      this.folderNameError = 'Por favor ingresa el código del documento';
      return;
    }

    // Validar formato del código (solo alfanumérico y guiones)
    const codePattern = /^[a-zA-Z0-9\-]+$/;
    if (!codePattern.test(this.folderName.trim())) {
      this.folderNameError = 'El código solo puede contener letras, números y guiones';
      return;
    }

    // Validar que el código no esté duplicado
    if (this.codeExists || this.folderNameError) {
      // El mensaje ya está mostrado debajo del campo, no mostrar notificación
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
          // Error de validación - mostrar mensaje específico
          if (error.error?.errors) {
            // Errores de validación de Laravel
            const validationErrors = error.error.errors;
            const firstError = Object.values(validationErrors)[0];
            if (Array.isArray(firstError) && firstError.length > 0) {
              errorMessage = firstError[0] as string;
            } else if (error.error.message) {
              errorMessage = error.error.message;
            } else {
              errorMessage = 'Error de validación. Verifica que el PDF sea válido.';
            }
          } else if (error.error?.message) {
            errorMessage = error.error.message;
          } else {
            errorMessage = 'Error de validación. Verifica que el PDF sea válido.';
          }
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

