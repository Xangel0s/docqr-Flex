import { Component, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Componente modal para confirmar cancelación de edición
 */
@Component({
  selector: 'app-cancel-confirm-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './cancel-confirm-modal.component.html',
  styleUrls: ['./cancel-confirm-modal.component.scss']
})
export class CancelConfirmModalComponent {
  @Input() isOpen: boolean = false;
  @Output() confirm = new EventEmitter<void>();
  @Output() cancel = new EventEmitter<void>();

  /**
   * Confirmar cancelación
   */
  onConfirm(): void {
    this.confirm.emit();
  }

  /**
   * Cancelar la acción
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

