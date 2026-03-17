import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Componente modal para confirmar eliminación
 */
@Component({
  selector: 'app-delete-confirm-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './delete-confirm-modal.component.html',
  styleUrls: ['./delete-confirm-modal.component.scss']
})
export class DeleteConfirmModalComponent {
  @Input() isOpen: boolean = false;
  @Input() documentName: string = '';
  @Input() documentType: string = '';
  @Output() confirm = new EventEmitter<void>();
  @Output() cancel = new EventEmitter<void>();

  /**
   * Confirmar eliminación
   */
  onConfirm(): void {
    this.confirm.emit();
  }

  /**
   * Cancelar eliminación
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

