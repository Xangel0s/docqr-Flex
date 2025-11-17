import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { VideoModalComponent } from '../../shared/components/video-modal/video-modal.component';
import { NotificationService } from '../../core/services/notification.service';

/**
 * Componente de Ayuda y Soporte
 */
@Component({
  selector: 'app-help',
  standalone: true,
  imports: [CommonModule, RouterModule, HeaderComponent, SidebarComponent, VideoModalComponent],
  templateUrl: './help.component.html',
  styleUrls: ['./help.component.scss']
})
export class HelpComponent implements OnInit {
  sidebarOpen: boolean = false;
  
  videoModalOpen: boolean = false;
  
  youtubeVideoUrl: string = 'https://www.youtube.com/watch?v=pDodbOAgjKM';
  
  videoThumbnail: string = 'assets/images/geominiatura.png';

  constructor(private notificationService: NotificationService) {}

  ngOnInit(): void {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }
  }

  /**
   * Abrir modal de video
   */
  openVideoModal(): void {
    if (!this.youtubeVideoUrl) {
      // Si no hay URL configurada, mostrar notificación
      this.notificationService.showInfo('El video tutorial aún no está disponible. Se agregará próximamente.');
      return;
    }
    this.videoModalOpen = true;
  }

  /**
   * Cerrar modal de video
   */
  closeVideoModal(): void {
    this.videoModalOpen = false;
  }

  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }
}

