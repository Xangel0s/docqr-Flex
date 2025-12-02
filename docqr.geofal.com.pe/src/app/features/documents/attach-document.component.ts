import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterModule } from '@angular/router';
import { DocqrService } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';

/**
 * Componente para crear carpeta y QR (Paso 1 del flujo "Adjuntar")
 * Este componente solo crea el registro y genera el QR, sin pedir el PDF
 */
@Component({
  selector: 'app-attach-document',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, HeaderComponent, SidebarComponent],
  templateUrl: './attach-document.component.html',
  styleUrls: ['./attach-document.component.scss']
})
export class AttachDocumentComponent implements OnInit {
  sidebarOpen: boolean = false;
  documentType: string = '';
  folderName: string = ''; // Solo la parte editable (sin las siglas)
  isCreating: boolean = false;
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

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService,
    public router: Router
  ) {}

  ngOnInit(): void {
    // En desktop, el sidebar siempre está visible (manejado por CSS)
    // En móvil, inicia cerrado
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
  }

  /**
   * Validar formulario
   */
  isFormValid(): boolean {
    return !!(this.documentType && this.folderName.trim() && !this.codeExists && !this.folderNameError && !this.checkingCode);
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
   * Crear carpeta y QR (sin PDF)
   */
  createDocument(): void {
    // Validaciones
    if (!this.documentType) {
      this.notificationService.showError('Por favor selecciona un tipo de documento');
      return;
    }

    if (!this.folderName.trim()) {
      this.notificationService.showError('Por favor ingresa el código de la carpeta');
      return;
    }

    // Validar formato del código (solo alfanumérico y guiones)
    const codePattern = /^[a-zA-Z0-9\-]+$/;
    if (!codePattern.test(this.folderName.trim())) {
      // El mensaje ya se muestra debajo del campo, no mostrar notificación
      return;
    }

    // Validar que el código no esté duplicado
    if (this.codeExists) {
      // El mensaje ya está mostrado debajo del campo, no mostrar notificación
      return;
    }

    // Combinar las siglas con el nombre de carpeta editable
    const fullFolderName = this.documentType + (this.folderName.trim() ? '-' + this.folderName.trim() : '');

    this.isCreating = true;

    // Crear documento sin PDF (solo genera el QR)
    this.docqrService.createDocumentWithoutPdf(fullFolderName).subscribe({
      next: (response) => {
        this.isCreating = false;

        if (response.success) {
          this.notificationService.showSuccess('Carpeta creada y QR generado exitosamente');
          
          // Redirigir al paso 2 (subir PDF)
          setTimeout(() => {
            this.router.navigate(['/documents/attach', response.data.qr_id, 'upload']);
          }, 1000);
        }
      },
      error: (error) => {
        this.isCreating = false;
        const errorMessage = error.error?.message || 'Error al crear la carpeta';
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

