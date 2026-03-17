import { Component, Input, Output, EventEmitter, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

/**
 * Componente modal para editar nombre de carpeta
 */
@Component({
  selector: 'app-edit-folder-modal',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './edit-folder-modal.component.html',
  styleUrls: ['./edit-folder-modal.component.scss']
})
export class EditFolderModalComponent implements OnChanges {
  @Input() isOpen: boolean = false;
  @Input() currentFolderName: string = '';
  @Input() documentName: string = '';
  @Output() save = new EventEmitter<string>();
  @Output() cancel = new EventEmitter<void>();

  newFolderName: string = '';
  documentType: string = 'CE'; // CE, IN, SU
  documentCode: string = '';

  /**
   * Cuando se abre el modal, inicializar con el nombre actual
   */
  ngOnChanges(changes: SimpleChanges): void {
    if (changes['isOpen']?.currentValue && this.currentFolderName) {
      this.newFolderName = this.currentFolderName;
      // Extraer tipo y código del nombre actual
      this.extractTypeAndCode();
    }
  }

  /**
   * Extraer tipo y código del nombre de carpeta actual
   */
  private extractTypeAndCode(): void {
    if (this.currentFolderName) {
      const parts = this.currentFolderName.split('-');
      if (parts.length >= 2) {
        this.documentType = parts[0].toUpperCase();
        this.documentCode = parts.slice(1).join('-'); // En caso de que el código tenga guiones
      }
    }
  }

  /**
   * Actualizar nombre completo cuando cambia tipo o código
   */
  updateFolderName(): void {
    if (this.documentType && this.documentCode) {
      this.newFolderName = `${this.documentType}-${this.documentCode}`;
    }
  }

  /**
   * Guardar cambios
   */
  onSave(): void {
    // Asegurar que el nombre completo esté actualizado
    this.updateFolderName();
    
    if (!this.isValidFormat()) {
      return;
    }
    
    if (this.newFolderName && this.newFolderName.trim() !== '') {
      this.save.emit(this.newFolderName.trim());
    }
  }

  /**
   * Cancelar
   */
  onCancel(): void {
    this.newFolderName = this.currentFolderName;
    this.cancel.emit();
  }

  /**
   * Cerrar al hacer clic en el backdrop
   */
  onBackdropClick(event: MouseEvent): void {
    if ((event.target as HTMLElement).classList.contains('modal-backdrop')) {
      this.onCancel();
    }
  }

  /**
   * Validar formato del nombre de carpeta (ej: CE-12345, IN-ABC, SU-XYZ)
   * Permite caracteres en español (Ñ, ñ, acentos)
   */
  isValidFormat(): boolean {
    if (!this.documentType || !this.documentCode || this.documentCode.trim() === '') {
      return false;
    }
    // Validar que el tipo sea válido
    const validTypes = ['CE', 'IN', 'SU'];
    if (!validTypes.includes(this.documentType)) {
      return false;
    }
    // Validar que el código no esté vacío y permita caracteres en español
    const code = this.documentCode.trim();
    if (code.length === 0) {
      return false;
    }
    // Validar que solo contenga caracteres alfanuméricos, guiones y caracteres en español
    const validPattern = /^[A-Za-z0-9ÑñÁÉÍÓÚáéíóúÜü\-]+$/;
    return validPattern.test(code);
  }

  /**
   * Obtener descripción del tipo de documento
   */
  getDocumentTypeDescription(): string {
    const descriptions: { [key: string]: string } = {
      'CE': 'Certificado',
      'IN': 'Informe de Ensayo',
      'SU': 'Suplemento'
    };
    return descriptions[this.documentType] || 'Desconocido';
  }
}

