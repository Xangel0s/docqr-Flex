import { Component, EventEmitter, Output, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-logout-confirm-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './logout-confirm-modal.component.html',
  styleUrls: ['./logout-confirm-modal.component.scss']
})
export class LogoutConfirmModalComponent {
  @Input() isOpen: boolean = false;
  @Output() confirm = new EventEmitter<void>();
  @Output() cancel = new EventEmitter<void>();

  onConfirm(): void {
    this.confirm.emit();
  }

  onCancel(): void {
    this.cancel.emit();
  }

  onBackdropClick(): void {
    this.cancel.emit();
  }
}

