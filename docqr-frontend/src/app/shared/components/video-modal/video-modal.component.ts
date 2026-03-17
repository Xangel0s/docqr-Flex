import { Component, Input, Output, EventEmitter, OnInit, OnDestroy, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';

/**
 * Componente modal para reproducir videos de YouTube
 */
@Component({
  selector: 'app-video-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './video-modal.component.html',
  styleUrls: ['./video-modal.component.scss']
})
export class VideoModalComponent implements OnInit, OnDestroy, OnChanges {
  @Input() isOpen: boolean = false;
  @Input() videoUrl: string = ''; // URL de YouTube (ej: https://www.youtube.com/watch?v=VIDEO_ID)
  @Input() videoTitle: string = 'Tutorial en Video';
  @Output() close = new EventEmitter<void>();

  safeVideoUrl: SafeResourceUrl | null = null;

  constructor(private sanitizer: DomSanitizer) {}

  ngOnInit(): void {
    this.updateBodyScroll();
    this.updateVideoUrl();
  }

  ngOnDestroy(): void {
    document.body.style.overflow = '';
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['isOpen']) {
      this.updateBodyScroll();
    }
    if (changes['videoUrl']) {
      this.updateVideoUrl();
    }
  }

  /**
   * Actualizar scroll del body según el estado del modal
   */
  private updateBodyScroll(): void {
    if (this.isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
  }

  /**
   * Convertir URL de YouTube a formato embed y sanitizar
   */
  private updateVideoUrl(): void {
    if (!this.videoUrl) {
      this.safeVideoUrl = null;
      return;
    }

    // Extraer ID del video de YouTube
    let videoId = '';
    
    // Formato 1: https://www.youtube.com/watch?v=VIDEO_ID
    if (this.videoUrl.includes('youtube.com/watch?v=')) {
      const urlParams = new URLSearchParams(this.videoUrl.split('?')[1]);
      videoId = urlParams.get('v') || '';
    }
    // Formato 2: https://youtu.be/VIDEO_ID
    else if (this.videoUrl.includes('youtu.be/')) {
      videoId = this.videoUrl.split('youtu.be/')[1].split('?')[0];
    }
    // Formato 3: Ya es un ID directo
    else if (this.videoUrl.length === 11) {
      videoId = this.videoUrl;
    }
    // Formato 4: Ya es una URL de embed
    else if (this.videoUrl.includes('youtube.com/embed/')) {
      videoId = this.videoUrl.split('youtube.com/embed/')[1].split('?')[0];
    }

    if (videoId) {
      // Crear URL de embed de YouTube
      const embedUrl = `https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0`;
      this.safeVideoUrl = this.sanitizer.bypassSecurityTrustResourceUrl(embedUrl);
    } else {
      this.safeVideoUrl = null;
    }
  }

  /**
   * Cerrar modal
   */
  closeModal(): void {
    this.isOpen = false;
    document.body.style.overflow = '';
    this.close.emit();
  }

  /**
   * Manejar clic fuera del modal
   */
  onBackdropClick(event: MouseEvent): void {
    if ((event.target as HTMLElement).classList.contains('modal-backdrop')) {
      this.closeModal();
    }
  }
}

