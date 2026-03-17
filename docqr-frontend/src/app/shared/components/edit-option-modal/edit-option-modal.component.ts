import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Tipo de edición seleccionada
 */
export type EditType = 'folder' | 'qr_position';

/**
 * Componente modal para seleccionar tipo de edición
 */
@Component({
  selector: 'app-edit-option-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './edit-option-modal.component.html',
  styleUrls: ['./edit-option-modal.component.scss']
})
export class EditOptionModalComponent {
  @Input() isOpen: boolean = false;
  @Input() documentName: string = '';
  @Output() selectOption = new EventEmitter<EditType>();
  @Output() cancel = new EventEmitter<void>();

  /**
   * Seleccionar editar nombre de carpeta
   */
  selectFolderEdit(): void {
    this.selectOption.emit('folder');
  }

  /**
   * Seleccionar editar posición del QR
   */
  selectQrPositionEdit(): void {
    this.selectOption.emit('qr_position');
  }

  /**
   * Cancelar
   */
  onCancel(): void {
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
}

