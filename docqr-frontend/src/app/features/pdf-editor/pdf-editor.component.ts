import { Component, OnInit, ViewChild, ElementRef, AfterViewInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Title } from '@angular/platform-browser';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { DocqrService, Document } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { CancelConfirmModalComponent } from '../../shared/components/cancel-confirm-modal/cancel-confirm-modal.component';
import { environment } from '../../../environments/environment';
import * as pdfjsLib from 'pdfjs-dist';
import { Canvas, Image as FabricImage, Object as FabricObject } from 'fabric';

// Configurar el worker de PDF.js
if (typeof window !== 'undefined') {
  pdfjsLib.GlobalWorkerOptions.workerSrc = `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.js`;
}

/**
 * Componente para editar PDF y posicionar QR usando Fabric.js (Arquitectura iLovePDF)
 * - Canvas inferior: PDF.js renderiza el PDF
 * - Canvas superior: Fabric.js maneja el QR como objeto interactivo
 */
@Component({
  selector: 'app-pdf-editor',
  standalone: true,
  imports: [CommonModule, HttpClientModule, RouterModule, HeaderComponent, SidebarComponent, CancelConfirmModalComponent],
  templateUrl: './pdf-editor.component.html',
  styleUrls: ['./pdf-editor.component.scss']
})
export class PdfEditorComponent implements OnInit, AfterViewInit, OnDestroy {
  @ViewChild('fabricCanvas', { static: false }) fabricCanvas!: ElementRef<HTMLCanvasElement>;

  sidebarOpen: boolean = false;
  qrId: string = '';
  document: Document | null = null;
  loading: boolean = true;
  saving: boolean = false;
  private retryAttempts: number = 0; // Protección contra bucles infinitos
  private readonly MAX_RETRY_ATTEMPTS: number = 1; // Solo permitir 1 reintento
  
  // Modal de confirmación de cancelación
  cancelModalOpen: boolean = false;
  
  // URLs
  pdfUrl: string = '';
  qrImageUrl: string = '';
  
  // Fabric.js
  private fabricCanvasInstance: Canvas | null = null;
  private pdfObject: FabricImage | null = null; // PDF como objeto bloqueado
  private qrObject: FabricImage | null = null;
  
  // Bandera para prevenir correcciones múltiples simultáneas que causan movimiento
  private isCorrectingQrPosition: boolean = false;
  
  // Bandera para prevenir logs/alertas múltiples cuando el QR está en el límite
  private lastSizeWarningTime: number = 0;
  private readonly SIZE_WARNING_COOLDOWN = 1000; // 1 segundo entre advertencias
  
  // PDF.js
  private pdfDoc: any = null;
  private pdfPage: any = null;
  private renderTask: any = null;
  
  // Dimensiones del PDF (estándar A4: 595x842 puntos a 72 DPI)
  private readonly STANDARD_PDF_WIDTH = 595;
  private readonly STANDARD_PDF_HEIGHT = 842;
  
  // MARGEN INVISIBLE (área segura) - Como en iLovePDF
  // Este margen previene que el QR cause la creación de páginas adicionales
  // El QR puede moverse libremente dentro de este área, pero no puede salirse
  // 5px desde todos los bordes = margen mínimo para evitar páginas adicionales
  // Reducido de 20px a 5px para permitir más flexibilidad cerca del footer
  private readonly SAFE_MARGIN = 0; // 0px de margen - libertad total para colocar el QR (configurable)
  
  private pdfDimensions = {
    width: this.STANDARD_PDF_WIDTH,
    height: this.STANDARD_PDF_HEIGHT,
    originalWidth: this.STANDARD_PDF_WIDTH,
    originalHeight: this.STANDARD_PDF_HEIGHT,
    scale: 1.0,
    offsetX: 0,
    offsetY: 0
  };

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private docqrService: DocqrService,
    private notificationService: NotificationService,
    private http: HttpClient,
    private titleService: Title
  ) {}

  ngOnInit(): void {
    this.qrId = this.route.snapshot.paramMap.get('qrId') || '';
  }

  ngAfterViewInit(): void {
    // Esperar a que Angular renderice los ViewChild
    setTimeout(() => {
      // Inicializar Fabric.js primero
      this.initFabricCanvas();
      
      // Esperar un poco más para asegurar que el canvas esté completamente inicializado
      setTimeout(() => {
        // Después de inicializar el canvas, cargar el documento
        if (this.qrId) {
          this.loadDocument();
        }
      }, 200);
    }, 200);
  }

  ngOnDestroy(): void {
    // Limpiar recursos
    if (this.renderTask) {
      this.renderTask.cancel();
    }
    if (this.fabricCanvasInstance) {
      this.fabricCanvasInstance.dispose();
    }
  }

  /**
   * Inicializar canvas de Fabric.js
   */
  private initFabricCanvas(): void {
    if (!this.fabricCanvas?.nativeElement) {
      setTimeout(() => {
        if (this.fabricCanvas?.nativeElement) {
          this.initFabricCanvas();
        } else {
          if (!environment.production) {
            console.error('No se pudo encontrar el canvas de Fabric.js después de varios intentos');
          }
        }
      }, 100);
      return;
    }

    if (this.fabricCanvasInstance) {
      return;
    }

    try {
      const canvasElement = this.fabricCanvas.nativeElement;

      // Asegurar que el elemento canvas tenga las dimensiones correctas y esté posicionado correctamente
      canvasElement.width = this.STANDARD_PDF_WIDTH;
      canvasElement.height = this.STANDARD_PDF_HEIGHT;
      canvasElement.style.width = `${this.STANDARD_PDF_WIDTH}px`;
      canvasElement.style.height = `${this.STANDARD_PDF_HEIGHT}px`;
      canvasElement.style.position = 'absolute';
      canvasElement.style.top = '0px';
      canvasElement.style.left = '0px';
      canvasElement.style.zIndex = '2';
      canvasElement.style.pointerEvents = 'auto';
      canvasElement.style.backgroundColor = 'transparent';

      // Crear instancia de Fabric.js con el mismo tamaño que el PDF
      this.fabricCanvasInstance = new Canvas(canvasElement, {
        width: this.STANDARD_PDF_WIDTH,
        height: this.STANDARD_PDF_HEIGHT,
        selection: true,
        preserveObjectStacking: true,
        backgroundColor: 'transparent'
      });

      // Configurar controles personalizados
      this.configureFabricControls();

      // Eventos de Fabric.js - Solo aplicar al QR
      this.fabricCanvasInstance.on('object:modified', (e: any) => {
        // Solo procesar si es el QR (verificar por tipo o referencia)
        if (this.qrObject && (e.target === this.qrObject || e.target?.type === 'image')) {
        this.onQrModified();
        }
      });

      // Prevenir que los controles afecten el QR cuando se mueve
      this.fabricCanvasInstance.on('object:moving', (e: any) => {
        // Solo aplicar restricciones si es el QR
        if (this.qrObject && (e.target === this.qrObject || e.target?.type === 'image')) {
        this.constrainQrToCanvas(e);
        }
      });

      // Prevenir que el QR se rompa al escalar
      // NOTA: Deshabilitado temporalmente porque el listener 'scaling' del objeto ya maneja esto
      // y tener ambos puede causar interferencia y movimiento no deseado
      /*
      this.fabricCanvasInstance.on('object:scaling', (e: any) => {
        // Solo aplicar restricciones si es el QR
        if (this.qrObject && (e.target === this.qrObject || e.target?.type === 'image')) {
        this.constrainQrSize(e);
        }
      });
      */
    } catch (error: any) {
      if (!environment.production) {
        console.error('Error al inicializar canvas de Fabric.js:', error);
      }
    }
  }

  /**
   * Esperar a que el canvas de Fabric.js esté inicializado
   */
  private async waitForFabricCanvas(): Promise<void> {
    let attempts = 0;
    const maxAttempts = 50; // 5 segundos máximo (50 * 100ms)

    while (!this.fabricCanvasInstance && attempts < maxAttempts) {
      await new Promise(resolve => setTimeout(resolve, 100));
      attempts++;
    }

    if (!this.fabricCanvasInstance) {
      throw new Error('No se pudo inicializar el canvas de Fabric.js');
    }
  }

  /**
   * Configurar controles visuales de Fabric.js (como iLovePDF)
   */
  private configureFabricControls(): void {
    if (!this.fabricCanvasInstance) return;

    // Personalizar apariencia de los controles
    FabricObject.prototype.set({
      cornerSize: 12,
      cornerColor: '#4285F4',
      cornerStrokeColor: '#FFFFFF',
      borderColor: '#4285F4',
      borderScaleFactor: 1.5,
      transparentCorners: false,
      rotatingPointOffset: 40
    });
  }

  /**
   * Cargar documento y renderizar PDF
   */
  async loadDocument(): Promise<void> {
    this.loading = true;
    
    try {
      // Obtener información del documento
      const response = await this.docqrService.getDocumentByQrId(this.qrId).toPromise();
      this.document = response?.data || null;
      
      if (!this.document) {
        this.notificationService.showError('Documento no encontrado');
        this.loading = false;
        return;
      }

      // Establecer título de la página con el nombre del documento
      const documentTitle = this.document.original_filename || this.document.folder_name || 'Documento';
      this.titleService.setTitle(`${documentTitle} - Editor - Geofal`);

      const basePdfUrl = this.document.pdf_original_url || this.document.pdf_url || '';
      this.pdfUrl = basePdfUrl ? this.convertToRelativeIfHttps(`${basePdfUrl}?t=${Date.now()}&editor=true`) : '';
      const baseQrUrl = this.convertToRelativeIfHttps(this.document.qr_image_url || '');
      const separator = baseQrUrl.includes('?') ? '&' : '?';
      this.qrImageUrl = `${baseQrUrl}${separator}t=${Date.now()}`;

      if (!this.pdfUrl || !this.qrImageUrl) {
        this.notificationService.showError('URLs del documento no disponibles');
        this.loading = false;
        return;
      }

      // Asegurar que el canvas de Fabric.js esté inicializado
      if (!this.fabricCanvasInstance) {
        await this.waitForFabricCanvas();
      }

      // Renderizar PDF como imagen de fondo en el canvas de Fabric.js
      await this.renderPdfAsBackground();

      // Cargar QR como objeto interactivo
      await this.loadQrToFabric();

      this.loading = false;
      } catch (error: any) {
        if (!environment.production) {
          console.error('Error al cargar documento:', error);
        }
      
      // El error ya fue manejado en renderPdfAsBackground con mensaje específico
      // Solo mostrar mensaje genérico si no hay mensaje específico
      if (!error.message || error.message === 'Error al cargar el documento') {
        this.notificationService.showError('Error al cargar el documento. Por favor, intenta nuevamente.');
      }
      
      this.loading = false;
    }
  }

  /**
   * Renderizar PDF como imagen de fondo en el canvas de Fabric.js
   */
  private async renderPdfAsBackground(): Promise<void> {
    if (!this.fabricCanvasInstance) {
      console.error('Canvas de Fabric.js no inicializado');
      return;
    }

    try {
      // Crear un canvas temporal para renderizar el PDF
      const tempCanvas = document.createElement('canvas');
      tempCanvas.width = this.STANDARD_PDF_WIDTH;
      tempCanvas.height = this.STANDARD_PDF_HEIGHT;
      const tempCtx = tempCanvas.getContext('2d');
      if (!tempCtx) {
        throw new Error('No se pudo obtener el contexto 2D del canvas temporal');
      }

      // Cancelar renderizado anterior si existe
      if (this.renderTask) {
        this.renderTask.cancel();
        this.renderTask = null;
      }

      tempCtx.fillStyle = 'white';
      tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
      // La validación previa puede ser demasiado estricta y bloquear PDFs válidos
      // PDF.js tiene su propia validación y manejo de errores

      // Cargar PDF solo si no está cargado o cambió la URL
      if (!this.pdfDoc || this.pdfDoc._transport?.url !== this.pdfUrl) {
        // Verificar primero qué está devolviendo el servidor (diagnóstico)
        try {
          const testResponse = await fetch(this.pdfUrl, {
            method: 'HEAD',
            headers: {
              'Accept': 'application/pdf',
              'X-Requested-With': 'XMLHttpRequest', // Evitar página de bienvenida de ngrok
            }
          });
          
          const contentType = testResponse.headers.get('content-type');
          if (contentType && !contentType.includes('application/pdf') && !contentType.includes('application/octet-stream')) {
            if (!environment.production) {
              console.error('El servidor no está devolviendo un PDF:', contentType);
            }
            throw new Error(`El servidor está devolviendo ${contentType} en lugar de application/pdf.`);
          }
        } catch (testError: any) {
          // Continuar de todas formas, PDF.js intentará cargar
        }
        
        const loadingTask = pdfjsLib.getDocument({
          url: this.pdfUrl,
          verbosity: 0, // Reducir warnings en consola
          stopAtErrors: false, // Continuar aunque haya errores menores
          isEvalSupported: false, // Deshabilitar eval para seguridad
          httpHeaders: {
            'Accept': 'application/pdf',
            'X-Requested-With': 'XMLHttpRequest', // Evitar página de bienvenida de ngrok
          },
          // Usar fetch en lugar de XMLHttpRequest para mejor compatibilidad con CORS
          useSystemFonts: false,
          // Configurar conCredentials para CORS
          withCredentials: false,
        });
        
        this.pdfDoc = await loadingTask.promise;
      }

      // Obtener primera página
      this.pdfPage = await this.pdfDoc.getPage(1);

      // Obtener viewport original
      const originalViewport = this.pdfPage.getViewport({ scale: 1.0 });
      this.pdfDimensions.originalWidth = originalViewport.width;
      this.pdfDimensions.originalHeight = originalViewport.height;

      // Calcular escala para ajustar a tamaño estándar (595x842)
      const scaleX = this.STANDARD_PDF_WIDTH / originalViewport.width;
      const scaleY = this.STANDARD_PDF_HEIGHT / originalViewport.height;
      const scale = Math.min(scaleX, scaleY);
      this.pdfDimensions.scale = scale;

      // Obtener viewport escalado
      const scaledViewport = this.pdfPage.getViewport({ scale });

      // Calcular offset de centrado
      const offsetX = (this.STANDARD_PDF_WIDTH - scaledViewport.width) / 2;
      const offsetY = (this.STANDARD_PDF_HEIGHT - scaledViewport.height) / 2;
      this.pdfDimensions.offsetX = offsetX;
      this.pdfDimensions.offsetY = offsetY;

      // Renderizar PDF centrado en canvas temporal
      const renderContext = {
        canvasContext: tempCtx,
        viewport: scaledViewport,
        transform: [1, 0, 0, 1, offsetX, offsetY]
      };

      this.renderTask = this.pdfPage.render(renderContext);
      await this.renderTask.promise;
      this.renderTask = null;

      const pdfImageUrl = tempCanvas.toDataURL('image/png');

      if (this.pdfObject) {
        this.fabricCanvasInstance.remove(this.pdfObject);
        this.pdfObject = null;
      }

      const pdfImage = await FabricImage.fromURL(pdfImageUrl, {
        crossOrigin: 'anonymous'
      });

      pdfImage.set({
        left: 0,
        top: 0,
        width: this.STANDARD_PDF_WIDTH,
        height: this.STANDARD_PDF_HEIGHT,
        scaleX: 1,
        scaleY: 1,
        selectable: false,
        evented: false,
        hasControls: false,
        hasBorders: false,
        lockMovementX: true,
        lockMovementY: true,
        lockRotation: true,
        lockScalingX: true,
        lockScalingY: true,
        excludeFromExport: false
      });

      // Agregar PDF al canvas (se agregará primero, quedando en el fondo)
      this.fabricCanvasInstance.add(pdfImage);
      this.pdfObject = pdfImage;

      // Asegurar que el objeto esté en la parte inferior (fondo)
      // En Fabric.js, el orden de adición determina el z-index (primero = fondo)
      // Si hay otros objetos, enviar el PDF al fondo
      if (this.fabricCanvasInstance.getObjects().length > 1) {
        this.fabricCanvasInstance.sendObjectToBack(pdfImage);
      }

      this.fabricCanvasInstance.renderAll();

    } catch (error: any) {
      if (!environment.production) {
        console.error('Error al renderizar PDF como fondo:', error);
        console.error('URL del PDF que falló:', this.pdfUrl);
        console.error('Detalles del error:', {
          name: error.name,
          message: error.message,
          stack: error.stack
        });
      }
      
      // Mensajes de error más específicos
      let errorMessage = 'Error al cargar el PDF';
      
      if (error.name === 'InvalidPDFException' || error.message?.includes('Invalid PDF')) {
        // Verificar si realmente es un problema del PDF o del servidor
        errorMessage = 'El archivo PDF no se pudo cargar. Puede estar corrupto, no existir en el servidor, o el servidor está devolviendo un error.';
      } else if (error.name === 'MissingPDFException' || error.message?.includes('Missing PDF')) {
        errorMessage = 'No se pudo encontrar el archivo PDF. Verifica que el archivo exista en el servidor.';
      } else if (error.message?.includes('NetworkError') || error.message?.includes('Failed to fetch')) {
        errorMessage = 'Error de red al cargar el PDF. Verifica tu conexión o que el servidor esté disponible.';
      } else if (error.message?.includes('html') || error.message?.includes('HTML') || error.message?.includes('404')) {
        errorMessage = 'El servidor no pudo encontrar el archivo PDF (404). Verifica que el archivo exista en: ' + this.pdfUrl;
      }
      
      this.notificationService.showError(errorMessage);
      throw new Error(errorMessage);
    }
  }

  /**
   * Validar que la URL del PDF sea accesible y sea un PDF válido
   * Esta validación es opcional y no bloquea la carga si falla
   */
  private async validatePdfUrl(url: string): Promise<void> {
    try {
      // Hacer una petición GET para obtener los primeros bytes
      // Usar timeout para evitar que se quede colgado
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 segundos timeout
      
      let response: Response;
      try {
        response = await fetch(url, { 
          method: 'GET',
          headers: {
            'Range': 'bytes=0-3' // Solo obtener los primeros 4 bytes para validar
          },
          signal: controller.signal
        });
        clearTimeout(timeoutId);
      } catch (fetchError: any) {
        clearTimeout(timeoutId);
        // Si falla con Range, intentar sin Range
        response = await fetch(url, { 
          method: 'GET',
          signal: controller.signal
        });
      }
      
      if (!response.ok) {
        // Si el servidor responde con error, verificar si es HTML
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('text/html')) {
          throw new Error('El servidor devolvió HTML en lugar del PDF. Verifica que la URL del archivo sea correcta.');
        }
        // Si no es HTML, puede ser un error del servidor pero el PDF puede existir
        // No bloquear, solo advertir
        return;
      }
      
      // Verificar Content-Type
      const contentType = response.headers.get('content-type');
      if (contentType && contentType.includes('text/html')) {
        throw new Error('El servidor devolvió HTML en lugar del PDF. Verifica que la URL del archivo sea correcta.');
      }
      
      // Verificar primeros bytes solo si tenemos datos
      const arrayBuffer = await response.arrayBuffer();
      const bytes = new Uint8Array(arrayBuffer);
      
      // Un PDF válido debe comenzar con "%PDF" (25 50 44 46 en hexadecimal)
      if (bytes.length >= 4) {
        if (bytes[0] !== 0x25 || bytes[1] !== 0x50 || bytes[2] !== 0x44 || bytes[3] !== 0x46) {
          // Verificar si es HTML (comienza con <)
          if (bytes[0] === 0x3C || (bytes.length > 4 && String.fromCharCode(bytes[0], bytes[1], bytes[2], bytes[3]).includes('<!'))) {
            throw new Error('El servidor devolvió HTML en lugar del PDF. Verifica que la URL del archivo sea correcta.');
          }
          // Si no es HTML ni PDF, puede ser un formato diferente pero válido
          // No bloquear, permitir que PDF.js intente cargarlo
        }
      }
    } catch (error: any) {
      // Si es un error de aborto (timeout), no lanzar error
      if (error.name === 'AbortError') {
        return; // Permitir que PDF.js intente cargar
      }
      
      // Si el error ya tiene un mensaje específico sobre HTML, lanzarlo
      if (error.message && (error.message.includes('HTML') || error.message.includes('html'))) {
        throw error;
      }
      
      // Para otros errores, no bloquear - permitir que PDF.js intente cargar
      // Algunos servidores pueden no soportar Range requests pero el PDF es válido
      return;
    }
  }


  /**
   * Cargar QR como objeto interactivo en Fabric.js
   */
  private async loadQrToFabric(): Promise<void> {
    if (!this.fabricCanvasInstance) {
      console.error('Canvas de Fabric.js no inicializado');
      return;
    }

    if (!this.qrImageUrl) {
      if (!environment.production) {
        console.error('URL de imagen QR no disponible');
      }
      return;
    }

    try {
      const img = await FabricImage.fromURL(this.qrImageUrl, {
        crossOrigin: 'anonymous'
      });

      if (!this.fabricCanvasInstance) {
        if (!environment.production) {
          console.error('Canvas de Fabric.js se perdió durante la carga');
        }
        return;
      }

      // Si ya existe un QR, eliminarlo
      if (this.qrObject) {
        this.fabricCanvasInstance.remove(this.qrObject);
      }

      // Configurar posición inicial o restaurar posición guardada
      // CRÍTICO: Las coordenadas guardadas están en el espacio estándar (595x842)
      // Necesitamos convertirlas de vuelta al espacio del canvas antes de posicionar el QR
      let x = 50;
      let y = 50;
      let width = 100;
      let height = 100;

      if (this.document?.qr_position) {
        const pos = this.document.qr_position;
        // Las coordenadas guardadas están en espacio estándar (595x842)
        const standardX = pos.x ?? 50;
        const standardY = pos.y ?? 50;
        const standardWidth = pos.width ?? 100;
        const standardHeight = pos.height ?? 100;
        
        // Obtener dimensiones reales del PDF y escala
        // CRÍTICO: Estas dimensiones deben estar disponibles después de renderPdfAsBackground()
        const pdfRealWidth = this.pdfDimensions.originalWidth || this.STANDARD_PDF_WIDTH;
        const pdfRealHeight = this.pdfDimensions.originalHeight || this.STANDARD_PDF_HEIGHT;
        const pdfScale = this.pdfDimensions.scale || 1.0;
        const pdfOffsetX = this.pdfDimensions.offsetX || 0;
        const pdfOffsetY = this.pdfDimensions.offsetY || 0;
        
        // Convertir del espacio estándar (595x842) al espacio real del PDF
        const qrXInRealPdf = (standardX / this.STANDARD_PDF_WIDTH) * pdfRealWidth;
        const qrYInRealPdf = (standardY / this.STANDARD_PDF_HEIGHT) * pdfRealHeight;
        const qrWidthInRealPdf = (standardWidth / this.STANDARD_PDF_WIDTH) * pdfRealWidth;
        const qrHeightInRealPdf = (standardHeight / this.STANDARD_PDF_HEIGHT) * pdfRealHeight;
        
        // Convertir del espacio real del PDF al espacio del canvas (escalado y con offset)
        // Esta es la conversión inversa de la que se hace al guardar
        const qrXInCanvas = (qrXInRealPdf * pdfScale) + pdfOffsetX;
        const qrYInCanvas = (qrYInRealPdf * pdfScale) + pdfOffsetY;
        const qrWidthInCanvas = qrWidthInRealPdf * pdfScale;
        const qrHeightInCanvas = qrHeightInRealPdf * pdfScale;
        
        // Usar coordenadas convertidas al canvas
        x = qrXInCanvas;
        y = qrYInCanvas;
        width = qrWidthInCanvas;
        height = qrHeightInCanvas;
        
      }

      // Obtener dimensiones originales del QR (debe ser cuadrado 300x300)
      const originalQrWidth = img.width || 300;
      const originalQrHeight = img.height || 300;
      const qrAspectRatio = originalQrWidth / originalQrHeight; // Debe ser 1.0 (cuadrado)
      
      // CRÍTICO: Corregir width/height para mantener relación de aspecto PERFECTA
      // El QR debe ser siempre cuadrado, usar width como referencia para mantener tamaño visual
      // Esto corrige casos como 125x137 -> 125x125 (no 131x131)
      let finalWidth = width;
      let finalHeight = width; // Usar width como referencia, no promedio
      
      const heightDiff = Math.abs(height - width) / Math.max(width, 1);
      
      const uniformScale = finalWidth / originalQrWidth;
      
      const currentWidth = originalQrWidth * uniformScale;
      const currentHeight = originalQrHeight * uniformScale;
      
      img.set({
        left: x,  // Usar valor exacto sin redondear (esquina superior izquierda)
        top: y,   // Usar valor exacto sin redondear (esquina superior izquierda)
        scaleX: uniformScale,  // Mismo valor para ambos
        scaleY: uniformScale,  // Mismo valor para ambos
        originX: 'left',   // Volver a usar esquina superior izquierda
        originY: 'top',    // Volver a usar esquina superior izquierda
        selectable: true,
        hasControls: true,
        hasBorders: true,
        lockRotation: true, // No permitir rotación
        lockScalingFlip: true,
        // CRÍTICO: Forzar escalado uniforme (mantener relación de aspecto)
        lockUniScaling: true, // Esto fuerza que scaleX y scaleY siempre sean iguales
        minScaleLimit: 50 / originalQrWidth, // Tamaño mínimo: 50px
        maxScaleLimit: 300 / originalQrWidth // Tamaño máximo: 300px
      });
      
      img.on('scaling', (e: any) => {
        // Prevenir correcciones múltiples simultáneas
        if (this.isCorrectingQrPosition) {
          return;
        }
        
        const obj = e.target as FabricImage;
        if (obj) {
          // Activar bandera para prevenir correcciones múltiples
          this.isCorrectingQrPosition = true;
          
          const originalQrWidth = obj.width || 300;
          const originalQrHeight = obj.height || 300;
          
          // CRÍTICO: Guardar el centro ANTES de cualquier modificación
          // Calcular el centro desde left/top y dimensiones actuales
          const currentScaleX = obj.scaleX || 1;
          const currentScaleY = obj.scaleY || 1;
          const currentWidth = originalQrWidth * currentScaleX;
          const currentHeight = originalQrHeight * currentScaleY;
          
          // Guardar posición actual EXACTA antes de modificar
          const savedLeft = obj.left || 0;
          const savedTop = obj.top || 0;
          
          // Calcular centro usando posición guardada
          const savedCenterX = savedLeft + currentWidth / 2;
          const savedCenterY = savedTop + currentHeight / 2;
          
          const minScale = 50 / originalQrWidth; // Tamaño mínimo: 50px
          const maxScale = 300 / originalQrWidth; // Tamaño máximo: 300px
          
          // Obtener el promedio de scaleX y scaleY para mantener relación de aspecto
          const avgScale = (currentScaleX + currentScaleY) / 2;
          
          // Aplicar límites para evitar que se rompa
          const clampedScale = Math.max(minScale, Math.min(maxScale, avgScale));
          
          // Calcular nuevas dimensiones
          const newWidth = originalQrWidth * clampedScale;
          const newHeight = originalQrHeight * clampedScale;
          
          // CRÍTICO: Calcular nueva posición left/top basándose en el centro guardado
          // Esto mantiene el centro visual en la misma posición (evita movimiento)
          const newLeft = savedCenterX - newWidth / 2;
          const newTop = savedCenterY - newHeight / 2;
          
          // CRÍTICO: Verificar si hay cambio significativo antes de aplicar
          // Si el cambio es menor a 0.001px, no aplicar para evitar movimiento mínimo
          const leftDiff = Math.abs(newLeft - savedLeft);
          const topDiff = Math.abs(newTop - savedTop);
          const scaleDiff = Math.abs(clampedScale - avgScale);
          
          // Solo aplicar si hay cambio significativo (mayor a 0.001px o 0.0001 de escala)
          // Umbral muy pequeño para evitar movimiento mínimo pero permitir correcciones necesarias
          if (leftDiff < 0.001 && topDiff < 0.001 && scaleDiff < 0.0001) {
            // No hay cambio significativo, salir sin hacer nada
            this.isCorrectingQrPosition = false;
            return;
          }
          
          // CRÍTICO: Aplicar escalas y posición manteniendo el centro fijo
          requestAnimationFrame(() => {
            if (obj && this.fabricCanvasInstance) {
              obj.set({
                scaleX: clampedScale,
                scaleY: clampedScale,
                left: newLeft,  // Posición calculada desde el centro guardado
                top: newTop     // Posición calculada desde el centro guardado
              });
              
              // Actualizar coordenadas después de aplicar cambios
              obj.setCoords();
              
              // Forzar renderizado
              this.fabricCanvasInstance.renderAll();
              
              // Desactivar bandera después de aplicar cambios
              this.isCorrectingQrPosition = false;
            }
          });
        }
      });
      
      // Listener después de modificar - Corregir cualquier desproporción
      // IMPORTANTE: Con origin en 'center', left/top ya representan el centro
      img.on('modified', () => {
        // Prevenir correcciones múltiples simultáneas
        if (this.isCorrectingQrPosition) {
          return;
        }
        
        const obj = this.qrObject;
        if (obj) {
          const originalQrWidth = obj.width || 300;
          const originalQrHeight = obj.height || 300;
          const scaleX = obj.scaleX || 1;
          const scaleY = obj.scaleY || 1;
          
          // Si hay diferencia, corregir usando promedio SIN mover el centro
          if (Math.abs(scaleX - scaleY) > 0.001) {
            // Activar bandera para prevenir correcciones múltiples
            this.isCorrectingQrPosition = true;
            
            // CRÍTICO: Guardar posición actual EXACTA antes de modificar
            const savedLeft = obj.left || 0;
            const savedTop = obj.top || 0;
            
            // Calcular centro usando coordenadas directas del objeto
            const currentWidth = originalQrWidth * scaleX;
            const currentHeight = originalQrHeight * scaleY;
            const savedCenterX = savedLeft + currentWidth / 2;
            const savedCenterY = savedTop + currentHeight / 2;
            
            const uniformScale = (scaleX + scaleY) / 2;
            const minScale = 50 / originalQrWidth;
            const maxScale = 300 / originalQrWidth;
            const clampedScale = Math.max(minScale, Math.min(maxScale, uniformScale));
            
            // Calcular nuevas dimensiones
            const newWidth = originalQrWidth * clampedScale;
            const newHeight = originalQrHeight * clampedScale;
            
            // CRÍTICO: Calcular nueva posición left/top basándose en el centro guardado
            const newLeft = savedCenterX - newWidth / 2;
            const newTop = savedCenterY - newHeight / 2;
            
            // CRÍTICO: Verificar si hay cambio significativo antes de aplicar
            // Si el cambio es menor a 0.001px, no aplicar para evitar movimiento mínimo
            const leftDiff = Math.abs(newLeft - savedLeft);
            const topDiff = Math.abs(newTop - savedTop);
            const scaleDiff = Math.abs(clampedScale - uniformScale);
            
            // Solo aplicar si hay cambio significativo (mayor a 0.001px o 0.0001 de escala)
            // Umbral muy pequeño para evitar movimiento mínimo pero permitir correcciones necesarias
            if (leftDiff < 0.001 && topDiff < 0.001 && scaleDiff < 0.0001) {
              // No hay cambio significativo, salir sin hacer nada
              this.isCorrectingQrPosition = false;
              return;
            }
            
            // CRÍTICO: Usar requestAnimationFrame para aplicar en el siguiente frame
            requestAnimationFrame(() => {
              if (obj && this.fabricCanvasInstance) {
                obj.set({
                  scaleX: clampedScale,
                  scaleY: clampedScale,
                  left: newLeft,  // Posición calculada desde el centro guardado
                  top: newTop     // Posición calculada desde el centro guardado
                });
                
                // Actualizar coordenadas después de aplicar cambios
                obj.setCoords();
                
                this.fabricCanvasInstance.renderAll();
                
                // Desactivar bandera después de aplicar cambios
                this.isCorrectingQrPosition = false;
              }
            });
          }
        }
      });
      
      // IMPORTANTE: NO llamar a constrainQrToCanvas() aquí
      // El QR debe cargarse en la posición exacta guardada
      // El usuario puede moverlo después si lo desea

      // Agregar QR al canvas (se agregará encima del PDF porque se agrega después)
      // En Fabric.js, los objetos se renderizan en el orden de inserción (último = arriba)
      this.fabricCanvasInstance.add(img);
      this.fabricCanvasInstance.setActiveObject(img);
      this.qrObject = img;

      this.fabricCanvasInstance.renderAll();

      } catch (error: any) {
        if (!environment.production) {
          console.error('Error al cargar QR en Fabric.js:', error);
        }
      throw error;
    }
  }

  /**
   * Restringir QR dentro de los límites del canvas
   * IMPORTANTE: Solo restringe si el usuario está moviendo el QR intencionalmente
   */
  private constrainQrToCanvas(e: any): void {
    if (!this.qrObject || !this.fabricCanvasInstance) return;

    const obj = e.target as FabricImage;
    if (!obj || obj !== this.qrObject) return; // Solo procesar si es el QR

    // Calcular dimensiones reales del QR usando getBoundingRect()
    // Esto es más preciso que multiplicar width * scaleX manualmente
    const boundingRect = obj.getBoundingRect();
    const qrWidth = boundingRect.width;
    const qrHeight = boundingRect.height;

    const pdfRealWidth = this.pdfDimensions.originalWidth || this.STANDARD_PDF_WIDTH;
    const pdfRealHeight = this.pdfDimensions.originalHeight || this.STANDARD_PDF_HEIGHT;
    const pdfScale = this.pdfDimensions.scale || 1.0;
    const pdfOffsetX = this.pdfDimensions.offsetX || 0;
    const pdfOffsetY = this.pdfDimensions.offsetY || 0;
    
    // Calcular dimensiones escaladas del PDF en el canvas
    const pdfScaledWidth = pdfRealWidth * pdfScale;
    const pdfScaledHeight = pdfRealHeight * pdfScale;
    
    // Convertir SAFE_MARGIN del espacio estándar al espacio escalado del canvas
    const safeMarginScaled = (this.SAFE_MARGIN / this.STANDARD_PDF_WIDTH) * pdfScaledWidth;
    const safeMarginYScaled = (this.SAFE_MARGIN / this.STANDARD_PDF_HEIGHT) * pdfScaledHeight;
    
    // Límites en el espacio del canvas (considerando el área real del PDF)
    const minX = pdfOffsetX + safeMarginScaled;
    const minY = pdfOffsetY + safeMarginYScaled;
    const maxX = pdfOffsetX + pdfScaledWidth - qrWidth - safeMarginScaled;
    const maxY = pdfOffsetY + pdfScaledHeight - qrHeight - safeMarginYScaled;

    // Obtener posición actual del objeto
    const currentLeft = obj.left || 0;
    const currentTop = obj.top || 0;

    // Solo aplicar restricciones si está fuera de los límites
    // No modificar si está dentro (evita movimiento no deseado)
    let needsUpdate = false;
    let newLeft = currentLeft;
    let newTop = currentTop;

    if (currentLeft < minX) {
      newLeft = minX;
      needsUpdate = true;
    } else if (currentLeft > maxX) {
      newLeft = maxX;
      needsUpdate = true;
    }

    if (currentTop < minY) {
      newTop = minY;
      needsUpdate = true;
    } else if (currentTop > maxY) {
      newTop = maxY;
      needsUpdate = true;
    }

    // Solo actualizar si realmente necesita corrección (está fuera de límites)
    if (needsUpdate) {
      obj.set({
        left: newLeft,
        top: newTop
      });
      obj.setCoords(); // Actualizar coordenadas de controles
      this.fabricCanvasInstance.renderAll();
    }
  }

  /**
   * Restringir tamaño del QR - MEJORADO para evitar que se rompa
   */
  private constrainQrSize(e: any): void {
    if (!this.qrObject) return;

    const obj = e.target as FabricImage;
    if (!obj) return;

    const originalQrWidth = obj.width || 300;
    const originalQrHeight = obj.height || 300;
    
    // Obtener escalas actuales
    let scaleX = obj.scaleX || 1;
    let scaleY = obj.scaleY || 1;
    
    // Usar promedio para mantener relación de aspecto
    const avgScale = (scaleX + scaleY) / 2;
    
    // Calcular dimensiones con escala uniforme
    const currentWidth = originalQrWidth * avgScale;
    const currentHeight = originalQrHeight * avgScale;

    // Límites estrictos para evitar que se rompa
    const minSize = 50; // Tamaño mínimo absoluto
    const maxSize = 300; // Tamaño máximo absoluto
    
    let finalScale = avgScale;
    
    // Validar tamaño mínimo - PREVENIR que se rompa
    if (currentWidth < minSize || currentHeight < minSize) {
      finalScale = minSize / originalQrWidth;
      // Solo loggear si no se ha loggeado recientemente (evitar spam)
      const now = Date.now();
      if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
        this.lastSizeWarningTime = now;
      }
    }

    // Validar tamaño máximo
    if (currentWidth > maxSize || currentHeight > maxSize) {
      finalScale = maxSize / originalQrWidth;
      // Solo loggear si no se ha loggeado recientemente (evitar spam)
      const now = Date.now();
      if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
        this.lastSizeWarningTime = now;
      }
    }
    
    // CRÍTICO: Si la escala no cambió significativamente, no hacer nada
    // Esto previene correcciones innecesarias cuando el QR está en el límite
    if (Math.abs(finalScale - avgScale) < 0.001) {
      return; // No hay cambio significativo, salir sin hacer nada
    }
    
    // Prevenir correcciones múltiples simultáneas
    if (this.isCorrectingQrPosition) {
      return;
    }
    
    // Activar bandera para prevenir correcciones múltiples
    this.isCorrectingQrPosition = true;
    
    // CRÍTICO: Guardar posición actual EXACTA antes de modificar
    const savedLeft = obj.left || 0;
    const savedTop = obj.top || 0;
    
    // Calcular centro usando posición guardada
    const savedCenterX = savedLeft + currentWidth / 2;
    const savedCenterY = savedTop + currentHeight / 2;
    
    // Calcular nuevas dimensiones
    const newWidth = originalQrWidth * finalScale;
    const newHeight = originalQrHeight * finalScale;
    
    // CRÍTICO: Calcular nueva posición left/top basándose en el centro guardado
    const finalNewLeft = savedCenterX - newWidth / 2;
    const finalNewTop = savedCenterY - newHeight / 2;
    
    // CRÍTICO: Verificar si hay cambio significativo antes de aplicar
    // Si el cambio es menor a 0.001px, no aplicar para evitar movimiento mínimo
    const leftDiff = Math.abs(finalNewLeft - savedLeft);
    const topDiff = Math.abs(finalNewTop - savedTop);
    const scaleDiff = Math.abs(finalScale - avgScale);
    
    // Solo aplicar si hay cambio significativo (mayor a 0.001px o 0.0001 de escala)
    // Umbral muy pequeño para evitar movimiento mínimo pero permitir correcciones necesarias
    if (leftDiff < 0.001 && topDiff < 0.001 && scaleDiff < 0.0001 && Math.abs(scaleX - scaleY) < 0.0001) {
      // No hay cambio significativo, salir sin hacer nada
      this.isCorrectingQrPosition = false;
      return;
    }
    
    // Aplicar escala uniforme (garantiza cuadrado y evita rotura) SIN mover el centro
    if (Math.abs(finalScale - avgScale) > 0.001 || Math.abs(scaleX - scaleY) > 0.001) {
      // CRÍTICO: Usar requestAnimationFrame para aplicar en el siguiente frame
      requestAnimationFrame(() => {
        if (obj && this.fabricCanvasInstance) {
      obj.set({
            scaleX: finalScale,
            scaleY: finalScale,
            left: finalNewLeft,  // Posición calculada desde el centro guardado
            top: finalNewTop     // Posición calculada desde el centro guardado
          });
          
          // Actualizar coordenadas después de aplicar cambios
          obj.setCoords();
          
          this.fabricCanvasInstance.renderAll();
          
          // Desactivar bandera después de aplicar cambios
          this.isCorrectingQrPosition = false;
        }
      });
    } else {
      // Si no hay cambios necesarios, solo desactivar la bandera
      this.isCorrectingQrPosition = false;
    }
  }

  /**
   * Callback cuando el QR es modificado
   */
  private onQrModified(): void {
    // Actualizar información en tiempo real si es necesario
    this.fabricCanvasInstance?.renderAll();
  }

  /**
   * Guardar posición del QR usando coordenadas exactas de Fabric.js
   */
  async savePosition(): Promise<void> {
    if (!this.document || !this.qrObject || !this.fabricCanvasInstance) {
      this.notificationService.showError('Documento o QR no cargado');
      return;
    }

    this.saving = true;

    try {
      // Obtener coordenadas exactas del objeto Fabric.js
      const obj = this.qrObject;
      
      // CRÍTICO: Calcular coordenadas relativas al objeto PDF, no al canvas completo
      // Esto asegura que la posición sea exacta independientemente de cómo esté centrado el PDF
      const qrLeft = obj.left || 0;
      const qrTop = obj.top || 0;
      
      // LÓGICA ADAPTADA DE LA RAMA MAIN: Convertir coordenadas del canvas al PDF real, luego al estándar
      // El objeto PDF en Fabric.js está en (0,0) con tamaño 595x842 (estándar)
      // Pero el PDF REAL puede ser más pequeño y estar centrado visualmente con offsets
      // Necesitamos convertir: Canvas -> PDF Real -> Espacio Estándar
      
      // Obtener dimensiones reales del PDF y offsets de centrado
      const pdfRealWidth = this.pdfDimensions.originalWidth || this.STANDARD_PDF_WIDTH;
      const pdfRealHeight = this.pdfDimensions.originalHeight || this.STANDARD_PDF_HEIGHT;
      const pdfScale = this.pdfDimensions.scale || 1.0;
      const pdfOffsetX = this.pdfDimensions.offsetX || 0;
      const pdfOffsetY = this.pdfDimensions.offsetY || 0;
      
      // El objeto PDF siempre está en (0,0) en Fabric.js
      const pdfObjectLeft = this.pdfObject?.left || 0;
      const pdfObjectTop = this.pdfObject?.top || 0;
      
      // 1. Calcular coordenadas relativas al objeto PDF estándar (595x842) en el canvas
      let leftRelativeToStandard = qrLeft - pdfObjectLeft;
      let topRelativeToStandard = qrTop - pdfObjectTop;
      
      // 2. Ajustar por el offset visual del PDF centrado
      // Si el PDF está centrado, el QR visual está relativo al PDF centrado, no al objeto completo
      // CRÍTICO: El offset se aplica en el espacio del canvas (595x842), pero el PDF real está centrado
      // Necesitamos ajustar las coordenadas para que sean relativas al PDF visual centrado
      let leftInCanvas = leftRelativeToStandard - pdfOffsetX;
      let topInCanvas = topRelativeToStandard - pdfOffsetY;
      
      // Validar que las coordenadas ajustadas no sean negativas (fuera del PDF visual)
      // Si son negativas, significa que el QR está fuera del área visible del PDF
      if (leftInCanvas < 0 || topInCanvas < 0) {
        // Si el QR está fuera del área visible, usar coordenadas relativas al objeto estándar
        // Esto puede pasar si el PDF está muy centrado y el QR está cerca del borde
        leftInCanvas = leftRelativeToStandard;
        topInCanvas = topRelativeToStandard;
      }
      
      // 3. Convertir del espacio escalado del canvas al espacio REAL del PDF
      // El PDF en el canvas está escalado, necesitamos desescalarlo
      // CRÍTICO: Usar precisión máxima para evitar errores de redondeo
      let qrXInRealPdf = leftInCanvas / pdfScale;
      let qrYInRealPdf = topInCanvas / pdfScale;
      
      // Calcular dimensiones
      const originalQrWidth = obj.width || 300;
      const originalQrHeight = obj.height || 300;
      const scaleX = obj.scaleX || 1;
      const scaleY = obj.scaleY || 1;
      const uniformScale = (scaleX + scaleY) / 2;
      
      // Calcular dimensiones usando escala uniforme (garantiza cuadrado)
      const finalWidth = originalQrWidth * uniformScale;
      const finalHeight = originalQrHeight * uniformScale;
      
      
      const boundingRect = obj.getBoundingRect();

      // Validar que el QR esté completamente dentro del canvas antes de guardar
      const canvasWidth = this.fabricCanvasInstance.width!;
      const canvasHeight = this.fabricCanvasInstance.height!;

      // 4. Convertir dimensiones del canvas al espacio real del PDF
      let qrWidthInRealPdf = finalWidth / pdfScale;
      let qrHeightInRealPdf = finalHeight / pdfScale;
      
      // 5. Validar que el QR esté dentro del área real del PDF
      const positionTolerance = 1; // 1px de tolerancia
      if (qrXInRealPdf < -positionTolerance || qrYInRealPdf < -positionTolerance || 
          qrXInRealPdf + qrWidthInRealPdf > pdfRealWidth + positionTolerance || 
          qrYInRealPdf + qrHeightInRealPdf > pdfRealHeight + positionTolerance) {
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
          this.notificationService.showError('El QR está fuera del área del documento. Ajusta la posición manualmente.');
        }
        this.saving = false;
        return;
      }
      
      // 6. Convertir al espacio estándar (595x842) para enviar al backend
      // El backend espera coordenadas en el espacio estándar y las convertirá al tamaño real
      // CRÍTICO: Usar porcentajes para máxima precisión y evitar errores de redondeo
      const xPercent = qrXInRealPdf / pdfRealWidth;
      const yPercent = qrYInRealPdf / pdfRealHeight;
      const widthPercent = qrWidthInRealPdf / pdfRealWidth;
      const heightPercent = qrHeightInRealPdf / pdfRealHeight;
      
      // Aplicar porcentajes al espacio estándar
      let standardX = xPercent * this.STANDARD_PDF_WIDTH;
      let standardY = yPercent * this.STANDARD_PDF_HEIGHT;
      let standardWidth = widthPercent * this.STANDARD_PDF_WIDTH;
      let standardHeight = heightPercent * this.STANDARD_PDF_HEIGHT;
      
      // CRÍTICO: Forzar que width y height sean iguales usando width como referencia
      // Esto previene que se guarde 125x137 en lugar de 125x125
      // Usar width como referencia para mantener el tamaño visual original
      const finalStandardWidth = Math.round(standardWidth * 100) / 100;
      const finalStandardHeight = Math.round(standardWidth * 100) / 100; // Usar width, no promedio
      
      // Asegurar que las coordenadas estén dentro del espacio estándar (con tolerancia)
      const roundingTolerance = 0.01; // Tolerancia de 0.01px para errores de redondeo
      standardX = Math.max(0, Math.min(this.STANDARD_PDF_WIDTH, standardX));
      standardY = Math.max(0, Math.min(this.STANDARD_PDF_HEIGHT, standardY));
      
      const position = {
        x: Math.round(standardX * 100) / 100, // Redondear a 2 decimales
        y: Math.round(standardY * 100) / 100,
        width: finalStandardWidth,  // SIEMPRE igual a height
        height: finalStandardHeight // SIEMPRE igual a width
      };

      // Validar tamaño (con tolerancia para evitar falsos positivos)
      const sizeTolerance = 0.5; // 0.5px de tolerancia
      if (position.width < 50 - sizeTolerance || position.width > 300 + sizeTolerance || 
          position.height < 50 - sizeTolerance || position.height > 300 + sizeTolerance) {
        // Solo mostrar error si no se ha mostrado recientemente
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
        this.notificationService.showError('El tamaño del QR debe estar entre 50px y 300px');
        }
        this.saving = false;
        return;
      }

      // Validar que esté completamente dentro del área real del PDF (con margen opcional)
      // Convertir el SAFE_MARGIN del espacio estándar al espacio real del PDF
      const safeMarginInRealPdf = (this.SAFE_MARGIN / this.STANDARD_PDF_WIDTH) * pdfRealWidth;
      const safeMarginYInRealPdf = (this.SAFE_MARGIN / this.STANDARD_PDF_HEIGHT) * pdfRealHeight;
      
      // Validar en el espacio real del PDF
             // Validar con tolerancia para evitar falsos positivos
             const marginTolerance = 0.5; // 0.5px de tolerancia
             if (qrXInRealPdf < safeMarginInRealPdf - marginTolerance || 
                 qrYInRealPdf < safeMarginYInRealPdf - marginTolerance || 
                 qrXInRealPdf + qrWidthInRealPdf > pdfRealWidth - safeMarginInRealPdf + marginTolerance || 
                 qrYInRealPdf + qrHeightInRealPdf > pdfRealHeight - safeMarginYInRealPdf + marginTolerance) {
               // Solo mostrar error si no se ha mostrado recientemente
               const now = Date.now();
               if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
                 this.lastSizeWarningTime = now;
        if (this.SAFE_MARGIN > 0) {
          this.notificationService.showError(`El QR debe estar dentro del área segura (margen de ${this.SAFE_MARGIN}px desde los bordes). Ajusta la posición.`);
        } else {
          this.notificationService.showError('El QR está fuera del área del documento. Ajusta la posición.');
                 }
        }
        this.saving = false;
        return;
      }

      // Usar el backend directamente (FPDI/TCPDF) - MÁS CONFIABLE
      // El backend garantiza que solo se procese la primera página y no cree páginas adicionales
      this.savePositionBackend(position);

    } catch (error: any) {
      if (!environment.production) {
        console.error('Error al guardar posición:', error);
      }
      this.notificationService.showError('Error al guardar la posición');
      this.saving = false;
    }
  }

  /**
   * Embebir QR en PDF usando pdf-lib (método exacto y profesional)
   * Crea un nuevo PDF solo con la primera página para evitar páginas adicionales
   */
  private async embedQrWithPdfLib(position: { x: number; y: number; width: number; height: number }, isRetry: boolean = false): Promise<void> {
    // Protección contra bucles infinitos
    if (isRetry && this.retryAttempts >= this.MAX_RETRY_ATTEMPTS) {
      this.notificationService.showError('Error: Se alcanzó el límite de reintentos. Por favor, intenta con otro archivo PDF.');
      this.saving = false;
      this.retryAttempts = 0; // Reset para el próximo intento
      return;
    }
    
    if (isRetry) {
      this.retryAttempts++;
    } else {
      this.retryAttempts = 0; // Reset si es el primer intento
    }
    try {
      const { PDFDocument } = await import('pdf-lib');

      // Cargar PDF original
      const pdfResponse = await fetch(this.pdfUrl!);
      const pdfBytes = await pdfResponse.arrayBuffer();
      const sourcePdfDoc = await PDFDocument.load(pdfBytes);

      // Obtener primera página del PDF original
      const sourcePages = sourcePdfDoc.getPages();
      if (sourcePages.length === 0) {
        throw new Error('El PDF no tiene páginas');
      }

      const sourceFirstPage = sourcePages[0];
      const { width: pageWidth, height: pageHeight } = sourceFirstPage.getSize();

      // CREAR UN NUEVO PDF SOLO CON LA PRIMERA PÁGINA
      // Esto garantiza que nunca se crearán páginas adicionales
      const newPdfDoc = await PDFDocument.create();
      
      // IMPORTANTE: copyPages() copia TODO el contenido de la página original:
      // - Todo el texto
      // - Todas las imágenes
      // - Todos los gráficos y elementos visuales
      // - El formato completo
      // - Las dimensiones exactas de la página
      // Es una copia exacta e idéntica del contenido original
      const [copiedPage] = await newPdfDoc.copyPages(sourcePdfDoc, [0]);
      newPdfDoc.addPage(copiedPage);

      const pages = newPdfDoc.getPages();
      const firstPage = pages[0];

      // Convertir coordenadas del canvas estándar (595x842) al PDF real
      const scaleX = pageWidth / this.STANDARD_PDF_WIDTH;
      const scaleY = pageHeight / this.STANDARD_PDF_HEIGHT;

      // IMPORTANTE: pdf-lib usa coordenadas desde la esquina inferior izquierda
      // Fabric.js usa desde la esquina superior izquierda
      // Necesitamos invertir Y: pdfY = pageHeight - (canvasY + height)
      let pdfX = position.x * scaleX;
      let pdfY = pageHeight - (position.y * scaleY + position.height * scaleY);
      let pdfWidth = position.width * scaleX;
      let pdfHeight = position.height * scaleY;

      // RESTRICCIÓN ESTRICTA: El QR es solo una superposición, NO debe crear páginas adicionales
      // Asegurar que el QR esté COMPLETAMENTE dentro de los límites de la página
      // MARGEN DE SEGURIDAD: Evitar que el QR esté exactamente en el borde
      // Esto previene problemas de redondeo que podrían causar páginas adicionales
      const safetyMargin = 2; // 2 puntos/mm de margen de seguridad
      
      const minX = safetyMargin;
      const minY = safetyMargin;
      const maxX = Math.floor(pageWidth) - safetyMargin;
      const maxY = Math.floor(pageHeight) - safetyMargin;
      
      // IMPORTANTE: NO ajustar automáticamente las coordenadas
      // Solo validar que estén dentro del área segura
      // Si están fuera, lanzar error (no mover)
      // Este método ya no se usa (se usa savePositionBackend), pero lo mantenemos por compatibilidad
      
      // Validar que esté dentro del área segura (sin ajustar)
      if (pdfX < minX || pdfY < minY || 
          pdfX + pdfWidth > maxX || 
          pdfY + pdfHeight > maxY) {
        // Solo loggear si no se ha loggeado recientemente (evitar spam)
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
        }
        throw new Error('El QR está fuera del área segura. Ajusta la posición en el editor.');
      }
      
      // Redondear a enteros para evitar problemas de precisión (sin ajustar posición)
      pdfX = Math.floor(pdfX);
      pdfY = Math.floor(pdfY);
      pdfWidth = Math.floor(pdfWidth);
      pdfHeight = Math.floor(pdfHeight);
      
      // Validación final: Asegurar que con el margen de seguridad, el QR esté completamente dentro
      const finalMaxX = Math.floor(pageWidth) - safetyMargin;
      const finalMaxY = Math.floor(pageHeight) - safetyMargin;

      // Validación final ABSOLUTA: El QR DEBE estar completamente dentro con margen de seguridad
      const isWithinBounds = 
        pdfX >= safetyMargin && 
        pdfY >= safetyMargin && 
        pdfX + pdfWidth <= finalMaxX && 
        pdfY + pdfHeight <= finalMaxY;

      if (!isWithinBounds) {
        // Solo mostrar error si no se ha mostrado recientemente
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
        this.notificationService.showError('El QR está fuera de los límites del documento. Por favor, ajusta la posición.');
        }
        this.saving = false;
        return;
      }

      // Cargar imagen QR en el nuevo documento
      const qrResponse = await fetch(this.qrImageUrl!);
      const qrBytes = await qrResponse.arrayBuffer();
      const qrBytesArray = new Uint8Array(qrBytes);
      
      let qrImage;
      if (this.qrImageUrl!.toLowerCase().includes('.png') || 
          (qrBytesArray.length > 0 && qrBytesArray[0] === 0x89 && qrBytesArray[1] === 0x50)) {
        qrImage = await newPdfDoc.embedPng(qrBytes);
      } else {
        qrImage = await newPdfDoc.embedJpg(qrBytes);
      }

      // Embebir QR en el PDF (usando el nuevo documento con solo 1 página)
      // IMPORTANTE: drawImage() solo dibuja una superposición, NO debería crear páginas adicionales
      // Las coordenadas ya están validadas para estar dentro de los límites
      firstPage.drawImage(qrImage, {
        x: pdfX,
        y: pdfY,
        width: pdfWidth,
        height: pdfHeight,
      });

      const pageCountAfterDraw = newPdfDoc.getPageCount();
      
      if (pageCountAfterDraw > 1) {
        // Solo loggear si no se ha loggeado recientemente (evitar spam)
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
        }
        
        while (newPdfDoc.getPageCount() > 1) {
          newPdfDoc.removePage(newPdfDoc.getPageCount() - 1);
        }
        
        if (newPdfDoc.getPageCount() > 1) {
          // Solo mostrar error si no se ha mostrado recientemente
          const now2 = Date.now();
          if (now2 - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
            this.lastSizeWarningTime = now2;
          this.notificationService.showError('Error al procesar el PDF. Por favor, intenta ajustar la posición del QR.');
          }
          this.saving = false;
          return;
        }
      }

      const pageCountBeforeSave = newPdfDoc.getPageCount();
      if (pageCountBeforeSave !== 1) {
        // Solo loggear si no se ha loggeado recientemente (evitar spam)
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
        }
        while (newPdfDoc.getPageCount() > 1) {
          newPdfDoc.removePage(newPdfDoc.getPageCount() - 1);
        }
      }

      const modifiedPdfBytes = await newPdfDoc.save();
      
      const verifyPdfDoc = await PDFDocument.load(modifiedPdfBytes);
      const finalPageCount = verifyPdfDoc.getPageCount();
      
      if (finalPageCount > 1) {
        // Solo mostrar error si no se ha mostrado recientemente
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
        this.notificationService.showError(`Error: El PDF generado tiene ${finalPageCount} páginas. Por favor, ajusta la posición del QR más arriba.`);
        }
        this.saving = false;
        return;
      }

      // Enviar al backend
      // Crear Blob directamente desde Uint8Array (TypeScript acepta esto aunque muestre warning)
      const pdfBlob = new Blob([modifiedPdfBytes as any], { type: 'application/pdf' });
      const pdfFile = new File([pdfBlob], 'modified.pdf', { type: 'application/pdf' });
      
      const formData = new FormData();
      formData.append('qr_id', this.qrId);
      formData.append('pdf', pdfFile, 'modified.pdf');
      formData.append('x', position.x.toString());
      formData.append('y', position.y.toString());
      formData.append('width', position.width.toString());
      formData.append('height', position.height.toString());

      // Verificar que el archivo se haya creado correctamente
      if (!pdfFile || pdfFile.size === 0) {
        this.notificationService.showError('Error: El PDF generado está vacío. Usando método alternativo...');
        this.savePositionBackend(position);
        return;
      }

      if (!environment.production) {
        console.log('Enviando PDF al backend:', {
          qr_id: this.qrId,
          file_size: pdfFile.size,
          file_name: pdfFile.name,
          file_type: pdfFile.type,
          position: position
        });
      }

      // IMPORTANTE: Verificar que el qr_id esté presente antes de enviar
      if (!this.qrId) {
        this.notificationService.showError('Error: No se encontró el ID del documento. Por favor, recarga la página.');
        this.saving = false;
        return;
      }

      // IMPORTANTE: Usar POST en lugar de PUT para FormData con archivos
      // PUT no maneja bien FormData con archivos en Laravel
      // Angular HttpClient automáticamente detecta FormData y establece el Content-Type correcto
      this.http.post(`${environment.apiUrl}/embed-pdf`, formData).subscribe({
        next: (response: any) => {
          if (response.success) {
            this.notificationService.showSuccess('✅ QR embebido exitosamente');
            // NO recargar el documento completo - solo actualizar las URLs
            // Recargar causaría problemas si el PDF original fue eliminado
            if (this.document) {
              // Actualizar URLs con cache buster para forzar recarga
              if (response.data?.final_pdf_url) {
                this.document.final_pdf_url = response.data.final_pdf_url;
              }
              this.document.qr_position = position;
              this.document.status = 'completed';
            }
            this.saving = false;
            this.retryAttempts = 0; // Reset en caso de éxito
          } else {
            this.notificationService.showError(response.message || 'Error al guardar PDF');
            this.saving = false;
          }
        },
        error: (error: any) => {
          if (!environment.production) {
            console.error('Error al guardar PDF:', error);
            console.error('Detalles del error:', {
              status: error?.status,
              statusText: error?.statusText,
              message: error?.message,
              error_message: error?.error?.message,
              errors: error?.error?.errors,
              error_data: error?.error
            });
          }
          
          // Si es error 422, mostrar mensaje específico
          if (error?.status === 422) {
            const errorMsg = error?.error?.message || 'Error de validación';
            const errors = error?.error?.errors;
            let detailedMsg = errorMsg;
            
            if (errors) {
              const firstError = Object.values(errors)[0];
              if (Array.isArray(firstError) && firstError.length > 0) {
                detailedMsg = firstError[0] as string;
              }
            }
            
            this.notificationService.showError(`Error de validación: ${detailedMsg}. Usando método alternativo...`);
          } else {
            this.notificationService.showError('Error al guardar PDF. Usando método alternativo...');
          }
          
          // Intentar con método del backend (FPDI) solo si no hemos excedido los reintentos
          if (this.retryAttempts < this.MAX_RETRY_ATTEMPTS) {
            this.savePositionBackend(position);
          } else {
            this.notificationService.showError('Error: No se pudo procesar el PDF. Por favor, intenta con otro archivo.');
            this.saving = false;
            this.retryAttempts = 0; // Reset para el próximo intento
          }
        }
      });

    } catch (error: any) {
      if (!environment.production) {
        console.error('Error al procesar PDF con pdf-lib:', error);
      }
      this.notificationService.showError('Error al procesar PDF. Usando método del backend...');
      this.savePositionBackend(position);
    }
  }

  /**
   * Guardar posición usando el backend directamente (FPDI/TCPDF)
   * Este método es más confiable porque el backend garantiza que solo se procese la primera página
   * y no cree páginas adicionales
   */
  private savePositionBackend(position: { x: number; y: number; width: number; height: number }): void {
    this.docqrService.embedQr(this.qrId, position).subscribe({
      next: (response) => {
        if (response.success) {
          // CRÍTICO: NO actualizar la posición del QR en el canvas después de guardar
          // El objeto QR ya está en la posición correcta visualmente
          // Actualizar con position.x/y causaría movimiento porque esas coordenadas son del espacio estándar (595x842)
          // no del espacio del canvas actual
          
          // Actualizar el estado del documento en memoria
          if (this.document) {
            this.document.qr_position = {
              x: position.x,
              y: position.y,
              width: position.width,
              height: position.height
            };
            this.document.status = 'completed';
            if (response.data?.final_pdf_url) {
              this.document.final_pdf_url = response.data.final_pdf_url;
            }
          }
          
          // ACTUALIZACIÓN INSTANTÁNEA: Actualizar URLs con nuevos timestamps para evitar caché
          // Esto permite que cuando el usuario descargue o vea el PDF, vea la versión actualizada
          // sin necesidad de refrescar la página
          if (this.document?.final_pdf_url) {
            // Actualizar URL del PDF final con cache buster
            // NOTA: No recargamos el PDF en el canvas porque mostraría el QR duplicado
            // (el QR ya está embebido en el PDF final, y también tenemos el objeto interactivo)
            // Solo actualizamos la URL para que cuando se descargue, sea la versión actualizada
            this.document.final_pdf_url = response.data?.final_pdf_url || this.document.final_pdf_url;
          }
          
          // Actualizar URL del QR con cache buster para forzar recarga si se muestra en otro lugar
          if (this.document?.qr_image_url) {
            const baseQrUrl = this.convertToRelativeIfHttps(this.document.qr_image_url);
            const separator = baseQrUrl.includes('?') ? '&' : '?';
            this.qrImageUrl = `${baseQrUrl}${separator}t=${Date.now()}`;
          }
          
          this.saving = false;
          
          // Mostrar mensaje de éxito con notificación
          this.notificationService.showSuccess('✅ QR reposicionado exitosamente. El PDF final se ha actualizado.');
          
          // NO descargar automáticamente - el usuario puede descargar cuando quiera usando el botón
        } else {
          this.notificationService.showError(response.message || 'Error al embebir QR');
          this.saving = false;
        }
      },
      error: (error: any) => {
        if (!environment.production) {
          console.error('Error al embebir QR en el backend:', error);
          console.error('Detalles del error:', {
            status: error?.status,
            statusText: error?.statusText,
            message: error?.message,
            error_message: error?.error?.message,
            error_data: error?.error
          });
        }
        
        const errorMessage = (error?.error?.message || error?.message || '').toLowerCase();
        const errorType = error?.error?.error_type || '';
        const is500Error = error?.status === 500;
        
        const isFpdiError = errorType === 'fpdi_compression' ||
                           errorMessage.includes('compression technique') || 
                           errorMessage.includes('not supported by the free parser') ||
                           errorMessage.includes('fpdi') ||
                           errorMessage.includes('pdf parser') ||
                           errorMessage.includes('compression') ||
                           (is500Error && errorMessage.length < 10);
        
        if (is500Error || isFpdiError) {
          // Solo intentar método alternativo si no hemos excedido los reintentos
          if (this.retryAttempts < this.MAX_RETRY_ATTEMPTS) {
            this.notificationService.showInfo('El PDF requiere procesamiento especial, usando método alternativo...');
            this.embedQrWithPdfLib(position, true).catch((fallbackError) => {
              if (!environment.production) {
                console.error('Error también en método alternativo:', fallbackError);
              }
              this.notificationService.showError('Error al procesar el PDF. Por favor, intenta con otro archivo.');
              this.saving = false;
              this.retryAttempts = 0; // Reset para el próximo intento
            });
          } else {
            this.notificationService.showError('Error: No se pudo procesar el PDF después de varios intentos. Por favor, intenta con otro archivo.');
            this.saving = false;
            this.retryAttempts = 0; // Reset para el próximo intento
          }
        } else {
          this.notificationService.showError('Error al embebir QR en el PDF: ' + (error?.error?.message || error?.message || 'Error desconocido'));
          this.saving = false;
          this.retryAttempts = 0; // Reset para el próximo intento
        }
      }
    });
  }

  /**
   * Convertir URL absoluta HTTP a relativa si estamos en HTTPS (ngrok)
   * Esto previene errores de Mixed Content
   */
  private convertToRelativeIfHttps(url: string): string {
    if (!url) return url;
    
    // Si estamos en HTTPS y la URL es HTTP con localhost, convertir a relativa
    if (window.location.protocol === 'https:' && url.startsWith('http://localhost:8000')) {
      // Extraer solo la ruta (ej: /api/files/qr/abc123)
      const urlObj = new URL(url);
      return urlObj.pathname + urlObj.search;
    }
    
    if (window.location.protocol === 'https:' && url.startsWith('http://')) {
      const urlObj = new URL(url);
      return urlObj.pathname + urlObj.search;
    }
    
    return url;
  }

  /**
   * Abrir modal de confirmación de cancelación
   */
  cancel(): void {
    this.cancelModalOpen = true;
  }

  /**
   * Cerrar modal de confirmación de cancelación
   */
  closeCancelModal(): void {
    this.cancelModalOpen = false;
  }

  /**
   * Confirmar cancelación y volver a la lista
   */
  confirmCancel(): void {
    this.closeCancelModal();
    this.router.navigate(['/documents']);
  }

  /**
   * Toggle sidebar
   */
  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }

  /**
   * Obtener información del QR para el sidebar
   */
  getQrInfo(): { url: string; size: string; position: string } | null {
    if (!this.qrObject || !this.document) return null;

    const width = Math.round((this.qrObject.width || 100) * (this.qrObject.scaleX || 1));
    const height = Math.round((this.qrObject.height || 100) * (this.qrObject.scaleY || 1));
    const x = Math.round(this.qrObject.left || 0);
    const y = Math.round(this.qrObject.top || 0);

    return {
      url: this.document.qr_url || '',
      size: `${width}px × ${height}px`,
      position: `X: ${x}px, Y: ${y}px`
    };
  }

  /**
   * Copiar URL del QR
   */
  copyQrUrl(): void {
    const info = this.getQrInfo();
    if (!info?.url) {
      this.notificationService.showError('No hay URL del QR disponible');
      return;
    }

    navigator.clipboard.writeText(info.url).then(() => {
      this.notificationService.showSuccess('URL del QR copiada al portapapeles');
    }).catch(() => {
      this.notificationService.showError('Error al copiar URL');
    });
  }

  /**
   * Verificar si el QR tiene URL con localhost
   */
  hasLocalhostUrl(): boolean {
    return !!(this.document?.qr_url && this.document.qr_url.includes('localhost'));
  }

  /**
   * Regenerar QR code con URL actualizada (corrige URLs con localhost)
   */
  regenerateQr(): void {
    if (!this.qrId) {
      this.notificationService.showError('No hay QR ID disponible');
      return;
    }

    this.notificationService.showInfo('Regenerando QR code con URL actualizada...');

    this.docqrService.regenerateQr(this.qrId).subscribe({
      next: (response) => {
        if (response.success) {
          this.notificationService.showSuccess('QR code regenerado exitosamente');
          // Recargar el documento para obtener las URLs actualizadas
          this.loadDocument();
        } else {
          this.notificationService.showError(response.message || 'Error al regenerar QR');
        }
      },
      error: (error) => {
        if (!environment.production) {
          console.error('Error al regenerar QR:', error);
        }
        this.notificationService.showError('Error al regenerar QR code: ' + (error?.error?.message || error?.message || 'Error desconocido'));
      }
    });
  }

  /**
   * Descargar imagen QR
   * Usa fetch con blob para forzar la descarga
   */
  downloadQrImage(resolution: 'original' | 'hd' = 'original'): void {
    if (!this.qrImageUrl || !this.qrId) {
      this.notificationService.showError('No hay imagen QR disponible');
      return;
    }

    // Construir URL con parámetro de resolución
    const baseUrl = this.qrImageUrl;
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set('resolution', resolution);
    url.searchParams.set('download', 'true');
    
    const filename = resolution === 'hd' 
      ? `qr-${this.qrId}-1024x1024.png`
      : `qr-${this.qrId}.png`;

    fetch(url.toString())
      .then(response => {
        if (!response.ok) throw new Error('Error al descargar QR');
        return response.blob();
      })
      .then(blob => {
        const downloadUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(downloadUrl);
        this.notificationService.showSuccess(
          resolution === 'hd' 
            ? '✅ QR en alta resolución descargado exitosamente' 
            : '✅ QR descargado exitosamente'
        );
      })
      .catch(error => {
        if (!environment.production) {
          console.error('Error al descargar QR:', error);
        }
        this.notificationService.showError('Error al descargar el QR');
      });
  }

  /**
   * Descargar PDF con QR embebido
   * Usa fetch con blob para forzar la descarga (no abre nueva pestaña)
   */
  downloadPdfWithQr(): void {
    if (!this.document?.final_pdf_url) {
      this.notificationService.showError('El PDF con QR aún no está disponible. Guarda primero la posición.');
      return;
    }

    // Agregar timestamp para evitar caché
    const urlWithCacheBuster = `${this.document.final_pdf_url}?t=${Date.now()}`;
    
    fetch(urlWithCacheBuster)
      .then(response => {
        if (!response.ok) {
          throw new Error('Error al obtener el PDF');
        }
        return response.blob();
      })
      .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = this.document?.original_filename || 'documento.pdf';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
        this.notificationService.showSuccess('✅ PDF con QR descargado exitosamente');
      })
      .catch(error => {
        if (!environment.production) {
          console.error('Error al descargar PDF:', error);
        }
        this.notificationService.showError('Error al descargar el PDF');
      });
  }
}
