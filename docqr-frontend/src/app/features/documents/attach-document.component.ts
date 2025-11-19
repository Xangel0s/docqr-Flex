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
    return !!(this.documentType && this.folderName.trim());
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
      this.notificationService.showError('El código solo puede contener letras, números y guiones');
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

