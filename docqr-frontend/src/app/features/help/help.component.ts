import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';

/**
 * Componente de Ayuda y Soporte
 */
@Component({
  selector: 'app-help',
  standalone: true,
  imports: [CommonModule, RouterModule, HeaderComponent, SidebarComponent],
  templateUrl: './help.component.html',
  styleUrls: ['./help.component.scss']
})
export class HelpComponent implements OnInit {
  sidebarOpen: boolean = false;

  ngOnInit(): void {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
  }

  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }
}

