import { Component, Input, Output, EventEmitter, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { Router } from '@angular/router';
import { LogoutConfirmModalComponent } from '../logout-confirm-modal/logout-confirm-modal.component';

/**
 * Componente Sidebar con navegación y menú hamburguesa
 */
@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterModule, LogoutConfirmModalComponent],
  templateUrl: './sidebar.component.html',
  styleUrls: ['./sidebar.component.scss']
})
export class SidebarComponent implements OnInit, OnDestroy {
  @Input() isOpen: boolean = false;
  @Output() closeSidebar = new EventEmitter<void>();
  
  showLogoutModal: boolean = false;
  isCollapsed: boolean = false;

  constructor(
    private authService: AuthService,
    private router: Router
  ) {}

  /**
   * Verificar si estamos en desktop
   */
  get isDesktop(): boolean {
    return typeof window !== 'undefined' && window.innerWidth >= 768;
  }

  ngOnInit(): void {
    // Cargar estado de colapso desde localStorage (solo en desktop)
    if (window.innerWidth >= 768) {
      const savedState = localStorage.getItem('sidebar_collapsed');
      if (savedState !== null) {
        this.isCollapsed = savedState === 'true';
      }
    }
  }

  ngOnDestroy(): void {
    // Cleanup si es necesario
  }

  /**
   * Cerrar sidebar (para móvil)
   */
  close(): void {
    this.closeSidebar.emit();
  }

  /**
   * Navegar y cerrar sidebar en móvil
   */
  navigateAndClose(): void {
    if (window.innerWidth < 768) {
      this.close();
    }
  }

  /**
   * Mostrar modal de confirmación de logout
   */
  logout(): void {
    this.showLogoutModal = true;
  }

  /**
   * Confirmar logout
   */
  confirmLogout(): void {
    this.showLogoutModal = false;
    this.authService.logout();
  }

  /**
   * Cancelar logout
   */
  cancelLogout(): void {
    this.showLogoutModal = false;
  }

  /**
   * Toggle colapsar/expandir sidebar (solo en desktop)
   */
  toggleCollapse(): void {
    if (window.innerWidth >= 768) {
      this.isCollapsed = !this.isCollapsed;
      localStorage.setItem('sidebar_collapsed', this.isCollapsed.toString());
    }
  }
}

