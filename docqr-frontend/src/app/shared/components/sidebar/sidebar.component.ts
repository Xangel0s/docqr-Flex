import { Component, Input, Output, EventEmitter, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';

/**
 * Componente Sidebar con navegación y menú hamburguesa
 */
@Component({
  selector: 'app-sidebar',
  standalone: true,
  imports: [CommonModule, RouterModule],
  templateUrl: './sidebar.component.html',
  styleUrls: ['./sidebar.component.scss']
})
export class SidebarComponent implements OnInit, OnDestroy {
  @Input() isOpen: boolean = false;
  @Output() closeSidebar = new EventEmitter<void>();

  ngOnInit(): void {
    // En desktop, el sidebar siempre está visible
    // En móvil, verificar si debe estar abierto
    if (window.innerWidth >= 768) {
      // En desktop, forzar que esté abierto
      // (se maneja con CSS, pero por si acaso)
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
   * Cerrar sesión
   */
  logout(): void {
    if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
      // TODO: Implementar lógica de cierre de sesión
      // Por ahora solo redirige al login/home
      window.location.href = '/';
    }
  }
}

