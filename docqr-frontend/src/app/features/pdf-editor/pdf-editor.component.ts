import { Component, OnInit, ViewChild, ElementRef, AfterViewInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
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
  imports: [CommonModule, FormsModule, HttpClientModule, RouterModule, HeaderComponent, SidebarComponent, CancelConfirmModalComponent],
  templateUrl: './pdf-editor.component.html',
  styleUrls: ['./pdf-editor.component.scss']
})
export class PdfEditorComponent implements OnInit, AfterViewInit, OnDestroy {
  sidebarOpen: boolean = false;
  qrId: string = '';
  document: Document | null = null;
  loading: boolean = true;
  saving: boolean = false;
  private retryAttempts: number = 0; // Protección contra bucles infinitos
  private readonly MAX_RETRY_ATTEMPTS: number = 1; // Solo permitir 1 reintento
  
  // Soporte para múltiples páginas
  totalPages: number = 1;
  currentPage: number = 1; // Página actual que se está mostrando/editando
  showAllPages: boolean = true; // Modo iLovePDF: mostrar todas las páginas
  
  // Sistema de múltiples QRs
  qrObjects: Map<number, FabricImage[]> = new Map(); // Mapa: página -> array de QRs
  selectedQr: { page: number; index: number } | null = null; // QR seleccionado
  
  // Modal de confirmación de cancelación
  cancelModalOpen: boolean = false;
  
  // URLs
  pdfUrl: string = '';
  qrImageUrl: string = '';
  
  // Fabric.js - Múltiples canvas (uno por página)
  private fabricCanvases: Map<number, Canvas> = new Map(); // Mapa: página -> Canvas
  private pdfPages: Map<number, FabricImage> = new Map(); // Mapa: página -> PDF renderizado
  
  // Compatibilidad con modo página única (legacy)
  private get fabricCanvasInstance(): Canvas | null {
    if (this.showAllPages && this.totalPages > 1) {
      return this.fabricCanvases.get(this.currentPage) || null;
    }
    return this.fabricCanvases.get(1) || null;
  }
  
  private get qrObject(): FabricImage | null {
    if (this.showAllPages && this.totalPages > 1) {
      const qrs = this.qrObjects.get(this.currentPage);
      return qrs && qrs.length > 0 ? qrs[0] : null;
    }
    const qrs = this.qrObjects.get(1);
    return qrs && qrs.length > 0 ? qrs[0] : null;
  }
  
  private get pdfObject(): FabricImage | null {
    if (this.showAllPages && this.totalPages > 1) {
      return this.pdfPages.get(this.currentPage) || null;
    }
    return this.pdfPages.get(1) || null;
  }
  
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
    // Esperar a que Angular renderice los ViewChild antes de cargar el documento
    setTimeout(() => {
      if (this.qrId) {
        this.loadDocument();
      }
    }, 300);
  }

  ngOnDestroy(): void {
    // Limpiar recursos
    if (this.renderTask) {
      this.renderTask.cancel();
    }
    // Limpiar todos los canvas
    this.fabricCanvases.forEach(canvas => canvas.dispose());
    this.fabricCanvases.clear();
  }

  /**
   * Inicializar canvas de Fabric.js (SOLO para modo página única)
   * Usa el mismo canvas que se crea dinámicamente para la página 1
   */
  private async initFabricCanvas(retryCount: number = 0): Promise<void> {
    // Solo inicializar si estamos en modo página única
    if (this.showAllPages && this.totalPages > 1) {
      if (!environment.production) {
        console.log('⏭️ Saltando initFabricCanvas (modo múltiples páginas)');
      }
      return; // En modo múltiples páginas, los canvas se crean en renderSinglePage
    }

    if (this.fabricCanvasInstance) {
      if (!environment.production) {
        console.log('✅ Canvas ya inicializado, saltando initFabricCanvas');
      }
      return; // Ya está inicializado
    }

    const maxRetries = 10;
    
    if (!environment.production) {
      console.log('🔍 Buscando elemento canvas fabric-canvas-1 en el DOM...');
    }
    
    // Esperar a que el canvas esté disponible en el DOM
    const canvasElement = await this.waitForCanvasElement('fabric-canvas-1', maxRetries, 200);
    
    if (!canvasElement) {
      console.error('❌ No se pudo encontrar el canvas de Fabric.js (fabric-canvas-1) después de varios intentos');
      return;
    }

    if (!environment.production) {
      console.log('✅ Elemento canvas encontrado en el DOM');
    }

    try {
      // Asegurar que el elemento canvas tenga las dimensiones correctas
      canvasElement.width = this.STANDARD_PDF_WIDTH;
      canvasElement.height = this.STANDARD_PDF_HEIGHT;
      canvasElement.style.width = `${this.STANDARD_PDF_WIDTH}px`;
      canvasElement.style.height = `${this.STANDARD_PDF_HEIGHT}px`;

      if (!environment.production) {
        console.log(`📐 Dimensiones del canvas: ${this.STANDARD_PDF_WIDTH}x${this.STANDARD_PDF_HEIGHT}`);
      }

      // Crear instancia de Fabric.js con el mismo tamaño que el PDF
      const fabricCanvas = new Canvas(canvasElement, {
        width: this.STANDARD_PDF_WIDTH,
        height: this.STANDARD_PDF_HEIGHT,
        selection: true,
        preserveObjectStacking: true,
        backgroundColor: 'transparent'
      });

      if (!environment.production) {
        console.log('✅ Instancia de Fabric.js creada');
      }

      // Guardar en el mapa (página 1 para modo página única)
      this.fabricCanvases.set(1, fabricCanvas);

      if (!environment.production) {
        console.log('✅ Canvas guardado en fabricCanvases.get(1)');
        console.log('Verificando getter:', this.fabricCanvasInstance ? 'Canvas accesible' : 'Canvas NO accesible');
      }

      // Configurar controles personalizados
      this.configureFabricControls(fabricCanvas);

      // Eventos de Fabric.js - Solo aplicar al QR
      fabricCanvas.on('object:modified', (e: any) => {
        // Solo procesar si es el QR (verificar por tipo o referencia)
        const qr = this.qrObject;
        const pdfImage = this.pdfPages.get(1);
        if (e.target && e.target !== pdfImage && (e.target === qr || e.target?.type === 'image')) {
          this.onQrModified();
        }
      });

      // Prevenir que el QR se salga de los límites
      fabricCanvas.on('object:moving', (e: any) => {
        const qr = this.qrObject;
        const pdfImage = this.pdfPages.get(1);
        if (e.target && e.target !== pdfImage && (e.target === qr || e.target?.type === 'image')) {
          this.constrainObjectToCanvas(e.target, fabricCanvas);
        }
      });

      // Tecla Delete para eliminar QR seleccionado
      fabricCanvas.on('selection:created', (e: any) => {
        const obj = e.selected?.[0];
        const pdfImage = this.pdfPages.get(1);
        
        if (obj && obj !== pdfImage) {
          console.log('✅ QR seleccionado en página única');
          
          const deleteHandler = (evt: KeyboardEvent) => {
            if ((evt.key === 'Delete' || evt.key === 'Backspace') && fabricCanvas.getActiveObject() === obj) {
              console.log('🗑️ Tecla Delete/Backspace presionada');
              this.deleteQr(obj, fabricCanvas, 1);
              document.removeEventListener('keydown', deleteHandler);
            }
          };
          
          document.addEventListener('keydown', deleteHandler);
          
          fabricCanvas.once('selection:cleared', () => {
            document.removeEventListener('keydown', deleteHandler);
          });
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
   * Esperar a que el canvas de Fabric.js esté inicializado (SOLO para modo página única)
   * NOTA: Este método ya no se usa. Se mantiene por compatibilidad pero initFabricCanvas
   * se llama directamente desde loadDocument ahora.
   */
  private async waitForFabricCanvas(): Promise<void> {
    // Solo esperar si estamos en modo página única
    if (this.showAllPages && this.totalPages > 1) {
      return; // En modo múltiples páginas, no necesitamos esperar este canvas
    }

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
  private configureFabricControls(canvas?: Canvas): void {
    const targetCanvas = canvas || this.fabricCanvasInstance;
    if (!targetCanvas) return;

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

      // Cargar PDF.js documento para obtener número de páginas
      const loadingTask = pdfjsLib.getDocument({
        url: this.pdfUrl,
        verbosity: 0,
        stopAtErrors: false,
        isEvalSupported: false,
        httpHeaders: { 'Accept': 'application/pdf', 'X-Requested-With': 'XMLHttpRequest' },
        useSystemFonts: false,
        withCredentials: false,
      });
      this.pdfDoc = await loadingTask.promise;
      this.totalPages = this.pdfDoc.numPages;

      if (!environment.production) {
        console.log(`📄 Documento analizado: ${this.totalPages} página(s)`);
      }

      // DETECCIÓN AUTOMÁTICA: Si tiene 1 página, usar método antiguo. Si tiene 2+, usar método nuevo
      if (this.totalPages === 1) {
        // MODO PÁGINA ÚNICA: Usar método antiguo (sistema clásico que funciona perfecto)
        if (!environment.production) {
          console.log('✅ Usando MODO PÁGINA ÚNICA (sistema clásico)');
        }
        this.showAllPages = false;
        this.currentPage = 1;
        
        // Inicializar el canvas de Fabric.js para modo página única
        if (!environment.production) {
          console.log('🎨 Inicializando canvas de Fabric.js...');
        }
        await this.initFabricCanvas();
        
        // Verificar que se inicializó correctamente
        if (!this.fabricCanvasInstance) {
          console.error('❌ El canvas no se inicializó correctamente');
          throw new Error('No se pudo inicializar el canvas de Fabric.js para página única');
        }
        
        if (!environment.production) {
          console.log('✅ Canvas inicializado correctamente');
          console.log('📄 Renderizando PDF en canvas...');
        }

        // Renderizar PDF como imagen de fondo en el canvas de Fabric.js
        await this.renderPdfAsBackground();

        if (!environment.production) {
          console.log('✅ PDF renderizado');
          console.log('🎯 Cargando QR al canvas...');
        }

        // Cargar QR normalmente
        await this.loadQrToFabric();
        
        if (!environment.production) {
          console.log('✅ QR cargado - Página única lista!');
        }
      } else {
        // MODO MÚLTIPLES PÁGINAS: Usar método nuevo (iLovePDF style)
        if (!environment.production) {
          console.log('✅ Usando MODO MÚLTIPLES PÁGINAS (sistema nuevo - iLovePDF style)');
        }
        this.showAllPages = true;
        this.currentPage = 1;

        // Renderizar todas las páginas (esto crea los canvas dinámicamente)
        await this.renderAllPages();

        // Cargar QRs existentes
        await this.loadExistingQrs();
      }

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
   * Renderizar PDF como imagen de fondo en el canvas de Fabric.js (SOLO para modo página única)
   */
  private async renderPdfAsBackground(): Promise<void> {
    // Solo usar este método en modo página única
    if (this.showAllPages && this.totalPages > 1) {
      if (!environment.production) {
        console.log('⏭️ Saltando renderPdfAsBackground (modo múltiples páginas)');
      }
      return; // En modo múltiples páginas, usar renderAllPages
    }

    if (!environment.production) {
      console.log('🖼️ renderPdfAsBackground: Iniciando...');
      console.log('Canvas instance:', this.fabricCanvasInstance ? 'Existe' : 'NO existe');
    }

    if (!this.fabricCanvasInstance) {
      console.error('❌ Canvas de Fabric.js no inicializado en renderPdfAsBackground');
      return;
    }

    // El PDF ya debe estar cargado desde loadDocument()
    if (!this.pdfDoc) {
      console.error('❌ PDF no está cargado. Esto no debería suceder.');
      throw new Error('PDF no cargado');
    }

    try {
      if (!environment.production) {
        console.log('📖 Obteniendo página 1 del PDF...');
      }

      // Obtener primera página
      this.pdfPage = await this.pdfDoc.getPage(1);

      if (!environment.production) {
        console.log('✅ Página obtenida');
      }

      // Obtener viewport original
      const originalViewport = this.pdfPage.getViewport({ scale: 1.0 });
      this.pdfDimensions.originalWidth = originalViewport.width;
      this.pdfDimensions.originalHeight = originalViewport.height;

      if (!environment.production) {
        console.log(`📐 Dimensiones originales del PDF: ${originalViewport.width}x${originalViewport.height}`);
      }

      // Calcular escala para ajustar a tamaño estándar (595x842)
      const scaleX = this.STANDARD_PDF_WIDTH / originalViewport.width;
      const scaleY = this.STANDARD_PDF_HEIGHT / originalViewport.height;
      const scale = Math.min(scaleX, scaleY);
      this.pdfDimensions.scale = scale;
      this.pdfDimensions.offsetX = (this.STANDARD_PDF_WIDTH - (originalViewport.width * scale)) / 2;
      this.pdfDimensions.offsetY = (this.STANDARD_PDF_HEIGHT - (originalViewport.height * scale)) / 2;

      if (!environment.production) {
        console.log(`📏 Escala calculada: ${scale}, Offset: (${this.pdfDimensions.offsetX}, ${this.pdfDimensions.offsetY})`);
      }

      // Obtener viewport escalado
      const scaledViewport = this.pdfPage.getViewport({ scale });

      // Crear canvas temporal para renderizar el PDF
      if (!environment.production) {
        console.log('🎨 Creando canvas temporal...');
      }

      const tempCanvas = document.createElement('canvas');
      tempCanvas.width = this.STANDARD_PDF_WIDTH;
      tempCanvas.height = this.STANDARD_PDF_HEIGHT;
      const tempCtx = tempCanvas.getContext('2d', { 
        alpha: false, // Fondo opaco para mejor rendimiento
        willReadFrequently: true 
      });
      
      if (!tempCtx) {
        throw new Error('No se pudo obtener el contexto 2D del canvas temporal');
      }

      // Fondo blanco
      tempCtx.fillStyle = 'white';
      tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);

      if (!environment.production) {
        console.log('🖌️ Renderizando PDF en canvas temporal...');
      }

      // Calcular offset de centrado
      const offsetX = (this.STANDARD_PDF_WIDTH - scaledViewport.width) / 2;
      const offsetY = (this.STANDARD_PDF_HEIGHT - scaledViewport.height) / 2;

      // Renderizar PDF centrado en canvas temporal
      const renderContext = {
        canvasContext: tempCtx,
        viewport: scaledViewport,
        transform: [1, 0, 0, 1, offsetX, offsetY],
        background: 'white'
      };

      // Cancelar renderizado anterior si existe
      if (this.renderTask) {
        this.renderTask.cancel();
      }

      this.renderTask = this.pdfPage.render(renderContext);
      await this.renderTask.promise;
      this.renderTask = null;

      if (!environment.production) {
        console.log('✅ PDF renderizado en canvas temporal');
        console.log('🔄 Convirtiendo canvas a imagen...');
      }

      // SOLUCIÓN DEFINITIVA: Usar setBackgroundImage de Fabric.js
      const fabricCanvas = this.fabricCanvasInstance;
      if (!fabricCanvas) {
        console.error('❌ Canvas de Fabric.js no disponible');
        throw new Error('Canvas no disponible');
      }

      // Convertir canvas temporal a data URL
      const pdfImageUrl = tempCanvas.toDataURL('image/png', 1.0);
      
      if (!environment.production) {
        const sizeKB = Math.round(pdfImageUrl.length / 1024);
        console.log(`📊 Imagen PDF: ${sizeKB}KB`);
      }

      // Remover PDF anterior si existe
      const pdfObj = this.pdfObject;
      if (pdfObj) {
        if (!environment.production) {
          console.log('🗑️ Removiendo PDF anterior');
        }
        fabricCanvas.remove(pdfObj);
        this.pdfPages.delete(this.showAllPages && this.totalPages > 1 ? this.currentPage : 1);
      }

      if (!environment.production) {
        console.log('🎨 Estableciendo PDF como BACKGROUND IMAGE de Fabric.js...');
      }

      // Crear imagen de Fabric.js desde el data URL
      const pdfImage = await FabricImage.fromURL(pdfImageUrl, {
        crossOrigin: 'anonymous'
      });

      if (!environment.production) {
        console.log(`✅ Imagen creada: ${pdfImage.width}x${pdfImage.height}`);
      }

      // Configurar la imagen para que actúe como fondo
      pdfImage.set({
        left: 0,
        top: 0,
        scaleX: this.STANDARD_PDF_WIDTH / pdfImage.width!,
        scaleY: this.STANDARD_PDF_HEIGHT / pdfImage.height!,
        originX: 'left',
        originY: 'top',
        selectable: false,  // No se puede seleccionar
        evented: false,     // No responde a eventos
        hasControls: false, // No tiene controles
        hasBorders: false,  // No tiene bordes
        lockMovementX: true,
        lockMovementY: true,
        lockRotation: true,
        lockScalingX: true,
        lockScalingY: true
      });

      // Agregar como primer objeto (fondo) del canvas
      // Al agregarlo primero, estará en el z-index más bajo
      fabricCanvas.add(pdfImage);
      
      // Guardar referencia en el mapa
      this.pdfPages.set(this.showAllPages && this.totalPages > 1 ? this.currentPage : 1, pdfImage);
      
      // Renderizar
      fabricCanvas.renderAll();

      if (!environment.production) {
        console.log('✅ PDF agregado como objeto de fondo (bloqueado)');
        console.log(`Escala aplicada: ${pdfImage.scaleX}x${pdfImage.scaleY}`);
        console.log(`Objetos totales en canvas: ${fabricCanvas.getObjects().length}`);
      }

      if (!environment.production) {
        console.log('✅ PDF renderizado como fondo - VISIBLE!');
        console.log(`📊 Objetos en canvas: ${fabricCanvas.getObjects().length}`);
      }

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
    if (!environment.production) {
      console.log('🎯 loadQrToFabric: Iniciando...');
      console.log('Canvas instance:', this.fabricCanvasInstance ? 'Existe' : 'NO existe');
      console.log('QR URL:', this.qrImageUrl ? 'Disponible' : 'NO disponible');
    }

    if (!this.fabricCanvasInstance) {
      console.error('❌ Canvas de Fabric.js no inicializado en loadQrToFabric');
      return;
    }

    if (!this.qrImageUrl) {
      console.error('❌ URL de imagen QR no disponible');
      return;
    }

    try {
      if (!environment.production) {
        console.log('📥 Cargando imagen QR desde:', this.qrImageUrl);
      }
      
      const img = await FabricImage.fromURL(this.qrImageUrl, {
        crossOrigin: 'anonymous'
      });

      if (!environment.production) {
        console.log('✅ Imagen QR cargada correctamente');
      }

      if (!this.fabricCanvasInstance) {
        console.error('❌ Canvas de Fabric.js se perdió durante la carga');
        return;
      }

      // Si ya existe un QR, eliminarlo
      const qr = this.qrObject;
      const canvasForQrRemove = this.fabricCanvasInstance;
      if (qr && canvasForQrRemove) {
        canvasForQrRemove.remove(qr);
        // Limpiar del mapa
        if (this.showAllPages && this.totalPages > 1) {
          const qrs = this.qrObjects.get(this.currentPage);
          if (qrs) {
            const index = qrs.indexOf(qr);
            if (index > -1) qrs.splice(index, 1);
          }
        } else {
          const qrs = this.qrObjects.get(1);
          if (qrs) {
            const index = qrs.indexOf(qr);
            if (index > -1) qrs.splice(index, 1);
          }
        }
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
                const canvas = this.fabricCanvasInstance;
                if (canvas) {
                  canvas.renderAll();
                }
              
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
              const canvas = this.fabricCanvasInstance;
              if (obj && canvas) {
                obj.set({
                  scaleX: clampedScale,
                  scaleY: clampedScale,
                  left: newLeft,  // Posición calculada desde el centro guardado
                  top: newTop     // Posición calculada desde el centro guardado
                });
                
                // Actualizar coordenadas después de aplicar cambios
                obj.setCoords();
                
                canvas.renderAll();
                
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
      const canvasForQrAdd = this.fabricCanvasInstance;
      if (canvasForQrAdd) {
        canvasForQrAdd.add(img);
        canvasForQrAdd.setActiveObject(img);
        // Guardar en el mapa
        if (this.showAllPages && this.totalPages > 1) {
          if (!this.qrObjects.has(this.currentPage)) {
            this.qrObjects.set(this.currentPage, []);
          }
          this.qrObjects.get(this.currentPage)!.push(img);
        } else {
          if (!this.qrObjects.has(1)) {
            this.qrObjects.set(1, []);
          }
          this.qrObjects.get(1)!.push(img);
        }
        canvasForQrAdd.renderAll();
        
        if (!environment.production) {
          console.log('✅ QR agregado al canvas y renderizado');
          console.log('Total de objetos en canvas:', canvasForQrAdd.getObjects().length);
        }
      }

      } catch (error: any) {
        console.error('❌ Error al cargar QR en Fabric.js:', error);
      throw error;
    }
  }

  /**
   * Restringir QR dentro de los límites del canvas
   * IMPORTANTE: Solo restringe si el usuario está moviendo el QR intencionalmente
   */
  private constrainQrToCanvas(e: any, canvas?: Canvas): void {
    const targetCanvas = canvas || this.fabricCanvasInstance;
    const qr = this.qrObject;
    if (!qr || !targetCanvas) return;

    const obj = e.target as FabricImage;
    if (!obj || obj !== qr) return; // Solo procesar si es el QR

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
      targetCanvas.renderAll();
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
          
                const canvas = this.fabricCanvasInstance;
                if (canvas) {
                  canvas.renderAll();
                }
          
          // Desactivar bandera después de aplicar cambios
          this.isCorrectingQrPosition = false;
        }
      });
    } else {
      // Si no hay cambios necesarios, solo desactivar la bandera
      this.isCorrectingQrPosition = false;
    }
  }

  // El método onQrModified con parámetros está más abajo

  /**
   * Guardar posición del QR usando coordenadas exactas de Fabric.js
   * Soporta modo de página única y modo múltiples páginas
   */
  async savePosition(): Promise<void> {
    // Si estamos en modo múltiples páginas, guardar todos los QRs
    if (this.showAllPages && this.totalPages > 1) {
      await this.saveAllQrsPositions();
      return;
    }

    // Modo página única (comportamiento original)
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
      const canvas = this.fabricCanvasInstance;
      if (!canvas) {
        this.saving = false;
        return;
      }
      const canvasWidth = canvas.width!;
      const canvasHeight = canvas.height!;

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
      this.savePositionBackend(position, this.currentPage);

    } catch (error: any) {
      if (!environment.production) {
        console.error('Error al guardar posición:', error);
      }
      this.notificationService.showError('Error al guardar la posición');
      this.saving = false;
    }
  }

  /**
   * Guardar todas las posiciones de QRs de todas las páginas
   */
  async saveAllQrsPositions(): Promise<void> {
    console.log('💾 saveAllQrsPositions iniciado');
    
    if (!this.document || !this.qrId) {
      console.error('❌ Documento o qrId no disponible');
      this.notificationService.showError('Documento no cargado');
      return;
    }

    this.saving = true;
    console.log('🔄 Estado saving activado');

    try {
      // Recopilar todos los QRs de todas las páginas
      const qrsToSave: Array<{ page: number; position: { x: number; y: number; width: number; height: number } }> = [];

      console.log(`🔍 Buscando QRs en ${this.totalPages} páginas...`);

      for (let pageNum = 1; pageNum <= this.totalPages; pageNum++) {
        const canvas = this.fabricCanvases.get(pageNum);
        const qrs = this.qrObjects.get(pageNum) || [];

        console.log(`Página ${pageNum}: Canvas=${canvas ? 'SÍ' : 'NO'}, QRs=${qrs.length}`);

        if (!canvas || qrs.length === 0) {
          continue;
        }

        // Para cada QR en esta página, calcular su posición
        for (const qrImage of qrs) {
          const obj = qrImage;
          const qrLeft = obj.left || 0;
          const qrTop = obj.top || 0;
          
          // Calcular dimensiones
          const originalQrWidth = obj.width || 300;
          const originalQrHeight = obj.height || 300;
          const scaleX = obj.scaleX || 1;
          const scaleY = obj.scaleY || 1;
          const uniformScale = (scaleX + scaleY) / 2;
          
          const finalWidth = originalQrWidth * uniformScale;
          const finalHeight = originalQrHeight * uniformScale;

          // Convertir al espacio estándar (595x842)
          const position = {
            x: Math.round(qrLeft * 100) / 100,
            y: Math.round(qrTop * 100) / 100,
            width: Math.round(finalWidth * 100) / 100,
            height: Math.round(finalHeight * 100) / 100
          };

          console.log(`✅ QR encontrado en página ${pageNum}:`, position);
          qrsToSave.push({ page: pageNum, position });
        }
      }

      console.log(`📊 Total QRs para guardar: ${qrsToSave.length}`);

      if (qrsToSave.length === 0) {
        console.warn('⚠️ No hay QRs para guardar');
        this.notificationService.showError('No hay QRs para guardar');
        this.saving = false;
        return;
      }

      // SIEMPRE usar método pdf-lib (funciona con PDFs comprimidos)
      // El método del backend (FPDI) falla con muchos PDFs comprimidos
      console.log(`📚 ${qrsToSave.length} QR(s) detectado(s), usando método pdf-lib (universal)`);
      await this.saveMultipleQrsPositions(qrsToSave);

    } catch (error: any) {
      console.error('❌❌❌ Error al guardar posiciones:', error);
      this.notificationService.showError('Error al guardar las posiciones');
      this.saving = false;
    }
  }

  /**
   * Guardar múltiples QRs en el PDF
   */
  private async saveMultipleQrsPositions(qrs: Array<{ page: number; position: { x: number; y: number; width: number; height: number } }>): Promise<void> {
    console.log('📦 saveMultipleQrsPositions iniciado', qrs);
    
    try {
      console.log('📚 Importando pdf-lib...');
      const { PDFDocument } = await import('pdf-lib');

      // Cargar PDF original
      console.log('📄 Cargando PDF original desde:', this.pdfUrl);
      const pdfResponse = await fetch(this.pdfUrl!);
      const pdfBytes = await pdfResponse.arrayBuffer();
      console.log(`✅ PDF cargado: ${pdfBytes.byteLength} bytes`);
      
      const sourcePdfDoc = await PDFDocument.load(pdfBytes);
      console.log('✅ PDF parseado correctamente');

      // Crear nuevo PDF con todas las páginas
      console.log('🆕 Creando nuevo documento PDF...');
      const newPdfDoc = await PDFDocument.create();
      const sourcePages = sourcePdfDoc.getPages();
      console.log(`📄 Copiando ${sourcePages.length} páginas...`);
      
      const pageIndices = Array.from({ length: sourcePages.length }, (_, i) => i);
      const copiedPages = await newPdfDoc.copyPages(sourcePdfDoc, pageIndices);
      copiedPages.forEach(page => newPdfDoc.addPage(page));
      console.log('✅ Páginas copiadas');

      const pages = newPdfDoc.getPages();
      console.log(`📄 Total páginas en nuevo PDF: ${pages.length}`);

      // Cargar imagen QR una vez
      console.log('🖼️ Cargando imagen QR desde:', this.qrImageUrl);
      const qrResponse = await fetch(this.qrImageUrl!);
      const qrBytes = await qrResponse.arrayBuffer();
      const qrBytesArray = new Uint8Array(qrBytes);
      console.log(`✅ Imagen QR cargada: ${qrBytes.byteLength} bytes`);
      
      let qrImage;
      if (this.qrImageUrl!.toLowerCase().includes('.png') || 
          (qrBytesArray.length > 0 && qrBytesArray[0] === 0x89 && qrBytesArray[1] === 0x50)) {
        console.log('📌 Embebiendo como PNG...');
        qrImage = await newPdfDoc.embedPng(qrBytes);
      } else {
        console.log('📌 Embebiendo como JPG...');
        qrImage = await newPdfDoc.embedJpg(qrBytes);
      }
      console.log('✅ Imagen QR embebida en el documento');

      // Embebir cada QR en su página correspondiente
      console.log(`🎯 Embebiendo ${qrs.length} QRs en sus páginas...`);
      for (const { page, position } of qrs) {
        const targetPageIndex = page - 1;
        if (targetPageIndex < 0 || targetPageIndex >= pages.length) {
          console.warn(`⚠️ Página ${page} fuera de rango, saltando`);
          continue;
        }

        const targetPage = pages[targetPageIndex];
        const { width: pageWidth, height: pageHeight } = targetPage.getSize();

        // Convertir coordenadas del canvas estándar (595x842) al PDF real
        const scaleX = pageWidth / this.STANDARD_PDF_WIDTH;
        const scaleY = pageHeight / this.STANDARD_PDF_HEIGHT;

        // pdf-lib usa coordenadas desde la esquina inferior izquierda
        let pdfX = position.x * scaleX;
        let pdfY = pageHeight - (position.y * scaleY + position.height * scaleY);
        let pdfWidth = position.width * scaleX;
        let pdfHeight = position.height * scaleY;

        console.log(`Página ${page}: Posición (${Math.floor(pdfX)}, ${Math.floor(pdfY)}), Tamaño (${Math.floor(pdfWidth)}x${Math.floor(pdfHeight)})`);

        console.log(`Página ${page}: Tamaño real del PDF (${pageWidth}x${pageHeight})`);
        
        // Validar que esté dentro de los límites
        const safetyMargin = 2;
        if (pdfX >= safetyMargin && pdfY >= safetyMargin && 
            pdfX + pdfWidth <= pageWidth - safetyMargin && 
            pdfY + pdfHeight <= pageHeight - safetyMargin) {
          
          // Dibujar QR con opacidad completa y encima de todo
          targetPage.drawImage(qrImage, {
            x: Math.floor(pdfX),
            y: Math.floor(pdfY),
            width: Math.floor(pdfWidth),
            height: Math.floor(pdfHeight),
            opacity: 1.0  // Forzar opacidad completa
          });
          console.log(`✅ QR dibujado en página ${page} con opacidad 1.0`);
        } else {
          console.warn(`⚠️ QR fuera de límites en página ${page}, saltando`);
          console.warn(`   pdfX=${pdfX}, pdfY=${pdfY}, pdfWidth=${pdfWidth}, pdfHeight=${pdfHeight}`);
          console.warn(`   Límites: x[${safetyMargin}, ${pageWidth - safetyMargin}], y[${safetyMargin}, ${pageHeight - safetyMargin}]`);
        }
      }

      console.log('💾 Guardando PDF modificado...');
      const modifiedPdfBytes = await newPdfDoc.save();
      console.log(`✅ PDF guardado: ${modifiedPdfBytes.byteLength} bytes`);
      
      const pdfBlob = new Blob([modifiedPdfBytes as any], { type: 'application/pdf' });
      const pdfFile = new File([pdfBlob], 'modified.pdf', { type: 'application/pdf' });
      
      const formData = new FormData();
      formData.append('qr_id', this.qrId);
      formData.append('pdf', pdfFile, 'modified.pdf');
      formData.append('x', qrs[0].position.x.toString()); // Usar primera posición como referencia
      formData.append('y', qrs[0].position.y.toString());
      formData.append('width', qrs[0].position.width.toString());
      formData.append('height', qrs[0].position.height.toString());
      formData.append('page_number', qrs[0].page.toString());

      console.log('📤 Enviando PDF al backend...');
      console.log('FormData:', {
        qr_id: this.qrId,
        pdf_size: pdfFile.size,
        total_qrs: qrs.length
      });

      this.http.post(`${environment.apiUrl}/embed-pdf`, formData).subscribe({
        next: (response: any) => {
          console.log('✅ Respuesta del backend:', response);
          
          if (response.success) {
            console.log(`🎉 ${qrs.length} QR(s) guardados exitosamente`);
            this.notificationService.showSuccess(`✅ ${qrs.length} QR(s) embebido(s) exitosamente`);
            if (this.document) {
              if (response.data?.final_pdf_url) {
                this.document.final_pdf_url = response.data.final_pdf_url;
                console.log('📄 PDF final URL actualizada:', this.document.final_pdf_url);
              }
              this.document.status = 'completed';
            }
            this.saving = false;
          } else {
            console.error('❌ Backend respondió con error:', response.message);
            this.notificationService.showError('Error al guardar las posiciones');
            this.saving = false;
          }
        },
        error: (error: any) => {
          console.error('❌❌❌ Error en la petición HTTP:', error);
          console.error('Detalles:', {
            status: error?.status,
            statusText: error?.statusText,
            message: error?.message,
            error: error?.error
          });
          this.notificationService.showError('Error al guardar las posiciones');
          this.saving = false;
        }
      });

    } catch (error: any) {
      if (!environment.production) {
        console.error('Error al procesar múltiples QRs:', error);
      }
      this.notificationService.showError('Error al procesar las posiciones');
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

      // Obtener todas las páginas del PDF original
      const sourcePages = sourcePdfDoc.getPages();
      if (sourcePages.length === 0) {
        throw new Error('El PDF no tiene páginas');
      }

      // CREAR UN NUEVO PDF CON TODAS LAS PÁGINAS
      const newPdfDoc = await PDFDocument.create();
      
      // Copiar todas las páginas del PDF original
      const pageIndices = Array.from({ length: sourcePages.length }, (_, i) => i);
      const copiedPages = await newPdfDoc.copyPages(sourcePdfDoc, pageIndices);
      copiedPages.forEach(page => newPdfDoc.addPage(page));

      const pages = newPdfDoc.getPages();
      
      // Obtener la página donde se colocará el QR (índice basado en 0)
      const targetPageIndex = this.currentPage - 1;
      if (targetPageIndex < 0 || targetPageIndex >= pages.length) {
        throw new Error(`Página ${this.currentPage} no existe. El PDF tiene ${pages.length} página(s).`);
      }
      
      const targetPage = pages[targetPageIndex];
      const { width: pageWidth, height: pageHeight } = targetPage.getSize();

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

      // Embebir QR en la página seleccionada del PDF
      // Las coordenadas ya están validadas para estar dentro de los límites
      targetPage.drawImage(qrImage, {
        x: pdfX,
        y: pdfY,
        width: pdfWidth,
        height: pdfHeight,
      });

      // Verificar que el número de páginas se mantenga igual
      const pageCountAfterDraw = newPdfDoc.getPageCount();
      const expectedPageCount = sourcePages.length;
      
      if (pageCountAfterDraw !== expectedPageCount) {
        const now = Date.now();
        if (now - this.lastSizeWarningTime > this.SIZE_WARNING_COOLDOWN) {
          this.lastSizeWarningTime = now;
          this.notificationService.showError(`Error: El PDF generado tiene ${pageCountAfterDraw} página(s) en lugar de ${expectedPageCount}. Por favor, intenta de nuevo.`);
        }
        this.saving = false;
        return;
      }

      const modifiedPdfBytes = await newPdfDoc.save();

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
      formData.append('page_number', this.currentPage.toString()); // Enviar número de página

      // Verificar que el archivo se haya creado correctamente
      if (!pdfFile || pdfFile.size === 0) {
        this.notificationService.showError('Error: El PDF generado está vacío. Usando método alternativo...');
        this.savePositionBackend(position, this.currentPage);
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
            this.savePositionBackend(position, this.currentPage);
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
      this.savePositionBackend(position, this.currentPage);
    }
  }

  /**
   * Guardar posición usando el backend directamente (FPDI/TCPDF)
   * Este método es más confiable porque el backend garantiza que solo se procese la primera página
   * y no cree páginas adicionales
   */
  private savePositionBackend(position: { x: number; y: number; width: number; height: number }, pageNumber: number = 1): void {
    this.docqrService.embedQr(this.qrId, position, pageNumber).subscribe({
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
  /**
   * Obtener array de números de página para el selector
   */
  getPageNumbers(): number[] {
    return Array.from({ length: this.totalPages }, (_, i) => i + 1);
  }

  /**
   * Manejar cambio de página (navegación rápida)
   */
  async onPageChange(): Promise<void> {
    if (!this.pdfDoc || this.currentPage < 1 || this.currentPage > this.totalPages) {
      return;
    }
    
    // Scroll a la página seleccionada
    this.scrollToPage(this.currentPage);
  }

  /**
   * Scroll a una página específica
   */
  scrollToPage(pageNumber: number): void {
    const pageElement = document.getElementById(`pdf-page-${pageNumber}`);
    if (pageElement) {
      pageElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
      this.currentPage = pageNumber;
    }
  }

  /**
   * Agregar un nuevo QR a la página actual
   */
  async addQrToPage(pageNumber: number, retryCount: number = 0): Promise<void> {
    console.log(`🎯 addQrToPage llamado para página ${pageNumber}, intento ${retryCount + 1}`);
    
    if (!this.qrImageUrl) {
      console.error('❌ No hay imagen QR disponible');
      this.notificationService.showError('No hay imagen QR disponible');
      return;
    }

    console.log('📌 URL del QR:', this.qrImageUrl);

    try {
      let canvas = this.fabricCanvases.get(pageNumber);
      
      console.log(`Canvas para página ${pageNumber}:`, canvas ? 'EXISTE' : 'NO EXISTE');
      
      if (!canvas) {
        if (retryCount >= 3) {
          console.error(`❌ No se pudo inicializar canvas después de ${retryCount} intentos`);
          this.notificationService.showError(`No se pudo inicializar el canvas para la página ${pageNumber}`);
          return;
        }
        
        console.log(`🔄 Inicializando canvas para página ${pageNumber}...`);
        await this.initPageCanvas(pageNumber);
        
        // Esperar un poco más para asegurar la inicialización
        await new Promise(resolve => setTimeout(resolve, 300));
        
        canvas = this.fabricCanvases.get(pageNumber);
        
        if (!canvas) {
          console.warn(`⚠️ Canvas aún no disponible, reintentando...`);
          return this.addQrToPage(pageNumber, retryCount + 1);
        }
        
        console.log('✅ Canvas inicializado correctamente');
      }

      console.log('📥 Cargando imagen QR desde URL...');
      
      // Cargar imagen QR
      const qrImage = await FabricImage.fromURL(this.qrImageUrl, {
        crossOrigin: 'anonymous',
      });

      console.log('✅ Imagen QR cargada:', qrImage);
      console.log(`Dimensiones originales: ${qrImage.width}x${qrImage.height}`);

      // Configurar tamaño y posición inicial
      const centerX = this.STANDARD_PDF_WIDTH / 2 - 50;
      const centerY = this.STANDARD_PDF_HEIGHT / 2 - 50;
      const scale = 100 / 300;
      
      console.log(`📍 Posición calculada: (${centerX}, ${centerY}), escala: ${scale}`);
      
      qrImage.set({
        left: centerX,
        top: centerY,
        scaleX: scale,
        scaleY: scale,
        selectable: true,
        hasControls: true,
        hasBorders: true,
        cornerSize: 12,
        cornerColor: '#4285F4',
        cornerStrokeColor: '#FFFFFF',
        borderColor: '#4285F4',
        transparentCorners: false
      });

      console.log('📦 Objetos en canvas ANTES de agregar:', canvas.getObjects().length);
      
      // Agregar al canvas ANTES de agregar al mapa (orden correcto)
      canvas.add(qrImage);
      console.log('➕ QR agregado al canvas');
      
      canvas.setActiveObject(qrImage);
      console.log('🎯 QR establecido como objeto activo');
      
      // Agregar al mapa de QRs
      if (!this.qrObjects.has(pageNumber)) {
        this.qrObjects.set(pageNumber, []);
      }
      this.qrObjects.get(pageNumber)!.push(qrImage);
      console.log('💾 QR guardado en mapa');

      // Renderizar DESPUÉS de agregar todo
      canvas.renderAll();
      console.log('🎨 Canvas renderizado');
      
      console.log('📦 Objetos en canvas DESPUÉS de agregar:', canvas.getObjects().length);
      console.log('📊 Total QRs en página:', this.qrObjects.get(pageNumber)?.length || 0);

      // Guardar referencia para eventos
      qrImage.on('modified', () => this.onQrModified(pageNumber, qrImage));
      qrImage.on('removed', () => this.onQrRemoved(pageNumber, qrImage));

      console.log(`✅ ✅ ✅ QR agregado exitosamente a página ${pageNumber}`);
      this.notificationService.showSuccess(`QR agregado a la página ${pageNumber}`);
    } catch (error: any) {
      console.error('❌❌❌ Error al agregar QR:', error);
      console.error('Stack:', error.stack);
      this.notificationService.showError('Error al agregar QR: ' + (error.message || 'Error desconocido'));
    }
  }

  /**
   * Eliminar QR seleccionado
   */
  removeSelectedQr(): void {
    if (!this.selectedQr) {
      this.notificationService.showError('No hay QR seleccionado');
      return;
    }

    const { page, index } = this.selectedQr;
    this.removeQrFromPage(page, index);
  }

  /**
   * Eliminar QR de una página específica
   */
  removeQrFromPage(pageNumber: number, index: number): void {
    const canvas = this.fabricCanvases.get(pageNumber);
    const qrs = this.qrObjects.get(pageNumber);

    if (canvas && qrs && qrs[index]) {
      canvas.remove(qrs[index]);
      qrs.splice(index, 1);
      canvas.renderAll();
      
      // Limpiar selección si el QR eliminado estaba seleccionado
      if (this.selectedQr && this.selectedQr.page === pageNumber && this.selectedQr.index === index) {
        this.selectedQr = null;
      }
      
      this.notificationService.showSuccess('QR eliminado');
    }
  }

  /**
   * Obtener el total de QRs en todas las páginas
   */
  getTotalQrsCount(): number {
    let total = 0;
    this.qrObjects.forEach(qrs => {
      total += qrs.length;
    });
    return total;
  }

  /**
   * Inicializar canvas para una página específica
   */
  private async initPageCanvas(pageNumber: number): Promise<void> {
    if (this.fabricCanvases.has(pageNumber)) {
      return; // Ya existe
    }

    await this.renderSinglePage(pageNumber);
  }

  /**
   * Renderizar todas las páginas del PDF (modo iLovePDF)
   */
  private async renderAllPages(): Promise<void> {
    if (!this.pdfDoc) {
      return;
    }

    // Limpiar canvas existentes
    this.fabricCanvases.forEach(canvas => canvas.dispose());
    this.fabricCanvases.clear();
    this.pdfPages.clear();
    this.qrObjects.clear();

    // Renderizar cada página
    for (let pageNum = 1; pageNum <= this.totalPages; pageNum++) {
      await this.renderSinglePage(pageNum);
    }

    // Cargar QRs existentes si hay
    await this.loadExistingQrs();
  }

  /**
   * Renderizar una página individual
   */
  private async renderSinglePage(pageNumber: number, retryCount: number = 0): Promise<void> {
    try {
      const page = await this.pdfDoc.getPage(pageNumber);
      const viewport = page.getViewport({ scale: 1.0 });
      
      // Calcular escala para ajustar a tamaño estándar
      const scaleX = this.STANDARD_PDF_WIDTH / viewport.width;
      const scaleY = this.STANDARD_PDF_HEIGHT / viewport.height;
      const scale = Math.min(scaleX, scaleY);
      const scaledViewport = page.getViewport({ scale });

      // Crear canvas temporal para renderizar PDF
      const tempCanvas = document.createElement('canvas');
      tempCanvas.width = this.STANDARD_PDF_WIDTH;
      tempCanvas.height = this.STANDARD_PDF_HEIGHT;
      const tempCtx = tempCanvas.getContext('2d');
      if (!tempCtx) {
        throw new Error('No se pudo obtener contexto 2D');
      }

      tempCtx.fillStyle = 'white';
      tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);

      // Calcular offset de centrado
      const offsetX = (this.STANDARD_PDF_WIDTH - scaledViewport.width) / 2;
      const offsetY = (this.STANDARD_PDF_HEIGHT - scaledViewport.height) / 2;

      // Renderizar PDF
      const renderContext = {
        canvasContext: tempCtx,
        viewport: scaledViewport,
        transform: [1, 0, 0, 1, offsetX, offsetY]
      };

      await page.render(renderContext).promise;

      // Crear imagen de Fabric.js desde el canvas
      const pdfImage = await FabricImage.fromURL(tempCanvas.toDataURL(), {
        crossOrigin: 'anonymous',
      });
      
      // Configurar propiedades después de cargar
      pdfImage.set({
        selectable: false,
        evented: false,
        lockMovementX: true,
        lockMovementY: true,
        lockRotation: true,
        lockScalingX: true,
        lockScalingY: true,
        hasControls: false,
        hasBorders: false,
      });

      pdfImage.set({
        left: 0,
        top: 0,
        width: this.STANDARD_PDF_WIDTH,
        height: this.STANDARD_PDF_HEIGHT,
      });

      // Esperar a que el canvas esté disponible en el DOM
      const canvasElement = await this.waitForCanvasElement(`fabric-canvas-${pageNumber}`, 10, 200);
      
      if (!canvasElement) {
        throw new Error(`No se pudo encontrar el elemento canvas para la página ${pageNumber} después de varios intentos`);
      }

      canvasElement.width = this.STANDARD_PDF_WIDTH;
      canvasElement.height = this.STANDARD_PDF_HEIGHT;
      canvasElement.style.width = `${this.STANDARD_PDF_WIDTH}px`;
      canvasElement.style.height = `${this.STANDARD_PDF_HEIGHT}px`;

      const fabricCanvas = new Canvas(canvasElement, {
        width: this.STANDARD_PDF_WIDTH,
        height: this.STANDARD_PDF_HEIGHT,
        selection: true,
        preserveObjectStacking: true,
        backgroundColor: 'white'
      });

      // Configurar controles personalizados para este canvas
      this.configureFabricControls(fabricCanvas);

      // Agregar PDF como fondo (al agregarlo primero, estará en el fondo)
      fabricCanvas.add(pdfImage);
      fabricCanvas.renderAll();

      // Guardar referencias
      this.fabricCanvases.set(pageNumber, fabricCanvas);
      this.pdfPages.set(pageNumber, pdfImage);
      this.qrObjects.set(pageNumber, []);

      // Configurar eventos del canvas
      this.configureCanvasEvents(fabricCanvas, pageNumber);

    } catch (error: any) {
      if (!environment.production) {
        console.error(`Error al renderizar página ${pageNumber}:`, error);
      }
      this.notificationService.showError(`Error al renderizar página ${pageNumber}`);
    }
  }

  /**
   * Esperar a que un elemento canvas esté disponible en el DOM
   */
  private async waitForCanvasElement(elementId: string, maxAttempts: number = 10, delayMs: number = 200): Promise<HTMLCanvasElement | null> {
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      const element = document.getElementById(elementId) as HTMLCanvasElement;
      if (element) {
        return element;
      }
      await new Promise(resolve => setTimeout(resolve, delayMs));
    }
    return null;
  }

  /**
   * Configurar eventos del canvas para una página
   */
  private configureCanvasEvents(canvas: Canvas, pageNumber: number): void {
    // Evento: Selección creada
    canvas.on('selection:created', (e: any) => {
      const obj = e.selected?.[0];
      if (obj && obj !== this.pdfPages.get(pageNumber)) {
        const qrs = this.qrObjects.get(pageNumber) || [];
        const index = qrs.indexOf(obj);
        if (index > -1) {
          this.selectedQr = { page: pageNumber, index };
        }
      }
    });

    // Evento: Selección limpiada
    canvas.on('selection:cleared', () => {
      this.selectedQr = null;
    });

    // Evento: Objeto en movimiento - aplicar límites por página
    canvas.on('object:moving', (e: any) => {
      const obj = e.target;
      if (!obj) return;

      // Verificar si es un QR (no el PDF de fondo)
      const pdfImage = this.pdfPages.get(pageNumber);
      if (obj === pdfImage) return;

      // Aplicar límites del canvas para esta página
      this.constrainObjectToCanvas(obj, canvas);
    });

    // Evento: Objeto modificado - guardar cambios
    canvas.on('object:modified', (e: any) => {
      const obj = e.target;
      if (!obj) return;

      // Verificar si es un QR
      const pdfImage = this.pdfPages.get(pageNumber);
      if (obj === pdfImage) return;

      this.onQrModified(pageNumber, obj);
    });

    // Agregar tecla Delete para eliminar QR seleccionado
    canvas.on('selection:created', (e: any) => {
      const obj = e.selected?.[0];
      const pdfImage = this.pdfPages.get(pageNumber);
      
      if (obj && obj !== pdfImage) {
        console.log(`✅ QR seleccionado en página ${pageNumber}`);
        
        // Evento de teclado para Delete
        const deleteHandler = (evt: KeyboardEvent) => {
          if ((evt.key === 'Delete' || evt.key === 'Backspace') && canvas.getActiveObject() === obj) {
            console.log('🗑️ Tecla Delete/Backspace presionada');
            this.deleteQr(obj, canvas, pageNumber);
            document.removeEventListener('keydown', deleteHandler);
          }
        };
        
        document.addEventListener('keydown', deleteHandler);
        
        // Limpiar evento cuando se deselecciona
        canvas.once('selection:cleared', () => {
          document.removeEventListener('keydown', deleteHandler);
        });
      }
    });
  }

  /**
   * Restringir objeto dentro de los límites del canvas (por página)
   */
  private constrainObjectToCanvas(obj: any, canvas: Canvas): void {
    if (!obj) return;

    const boundingRect = obj.getBoundingRect();
    const objWidth = boundingRect.width;
    const objHeight = boundingRect.height;

    // Límites del canvas (estándar 595x842)
    const margin = 10; // Margen de seguridad
    const minX = margin;
    const minY = margin;
    const maxX = this.STANDARD_PDF_WIDTH - objWidth - margin;
    const maxY = this.STANDARD_PDF_HEIGHT - objHeight - margin;

    // Obtener posición actual
    let currentLeft = obj.left || 0;
    let currentTop = obj.top || 0;

    // Aplicar restricciones
    if (currentLeft < minX) currentLeft = minX;
    if (currentLeft > maxX) currentLeft = maxX;
    if (currentTop < minY) currentTop = minY;
    if (currentTop > maxY) currentTop = maxY;

    // Actualizar posición si cambió
    obj.set({
      left: currentLeft,
      top: currentTop
    });

    obj.setCoords();
  }

  /**
   * Mostrar menú contextual para eliminar QR
   */
  private showContextMenu(event: MouseEvent, obj: any, canvas: Canvas, pageNumber: number): void {
    console.log('🖱️ showContextMenu llamado', { pageNumber, x: event.clientX, y: event.clientY });
    
    // Remover menú anterior si existe
    const oldMenu = document.querySelector('.context-menu');
    if (oldMenu) {
      oldMenu.remove();
    }

    // Crear menú contextual
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.style.position = 'fixed';
    menu.style.left = `${event.clientX}px`;
    menu.style.top = `${event.clientY}px`;
    menu.style.background = 'white';
    menu.style.border = '1px solid #ddd';
    menu.style.borderRadius = '8px';
    menu.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    menu.style.zIndex = '99999';
    menu.style.padding = '8px 0';
    menu.style.minWidth = '160px';
    menu.style.fontFamily = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    // Opción: Eliminar QR
    const deleteOption = document.createElement('div');
    deleteOption.className = 'context-menu-item';
    deleteOption.style.padding = '10px 16px';
    deleteOption.style.cursor = 'pointer';
    deleteOption.style.display = 'flex';
    deleteOption.style.alignItems = 'center';
    deleteOption.style.gap = '10px';
    deleteOption.style.color = '#dc2626';
    deleteOption.style.fontSize = '14px';
    deleteOption.innerHTML = `
      <span class="material-symbols-outlined" style="font-size: 20px;">delete</span>
      <span>Eliminar QR</span>
    `;

    // Hover effect
    deleteOption.addEventListener('mouseenter', () => {
      deleteOption.style.background = '#fee2e2';
    });
    deleteOption.addEventListener('mouseleave', () => {
      deleteOption.style.background = 'transparent';
    });

    // Click: Eliminar QR
    deleteOption.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      console.log('🗑️ Click en eliminar QR');
      this.deleteQr(obj, canvas, pageNumber);
      menu.remove();
    });

    menu.appendChild(deleteOption);
    document.body.appendChild(menu);
    
    console.log('✅ Menú contextual agregado al DOM', menu);

    // Cerrar menú al hacer click fuera
    const closeMenu = (e: MouseEvent) => {
      if (!menu.contains(e.target as Node)) {
        console.log('❌ Click fuera del menú, cerrando');
        menu.remove();
        document.removeEventListener('click', closeMenu);
      }
    };

    // Delay para evitar que se cierre inmediatamente
    setTimeout(() => {
      document.addEventListener('click', closeMenu);
    }, 100);
  }

  /**
   * Eliminar un QR del canvas
   */
  private deleteQr(obj: any, canvas: Canvas, pageNumber: number): void {
    // Eliminar del canvas
    canvas.remove(obj);
    canvas.renderAll();

    // Eliminar del mapa de QRs
    const qrs = this.qrObjects.get(pageNumber);
    if (qrs) {
      const index = qrs.indexOf(obj);
      if (index > -1) {
        qrs.splice(index, 1);
      }
    }

    // Limpiar selección si era el QR seleccionado
    if (this.selectedQr?.page === pageNumber) {
      const qrs = this.qrObjects.get(pageNumber);
      if (qrs) {
        const index = qrs.indexOf(obj);
        if (this.selectedQr.index === index) {
          this.selectedQr = null;
        }
      }
    }

    this.notificationService.showSuccess('QR eliminado correctamente');
  }

  /**
   * Cargar QRs existentes desde el documento
   */
  private async loadExistingQrs(): Promise<void> {
    if (!this.document?.qr_position || !this.qrImageUrl) {
      return;
    }

    // Por ahora, cargar el QR en la primera página (compatibilidad)
    // En el futuro, se puede extender para soportar múltiples QRs guardados
    const pos = this.document.qr_position;
    const pageNumber = 1; // Por defecto primera página

    const canvas = this.fabricCanvases.get(pageNumber);
    if (!canvas) {
      return;
    }

    try {
      const qrImage = await FabricImage.fromURL(this.qrImageUrl, {
        crossOrigin: 'anonymous',
      });

      qrImage.set({
        left: pos.x || 50,
        top: pos.y || 50,
        scaleX: (pos.width || 100) / 300,
        scaleY: (pos.height || 100) / 300,
        selectable: true,
        hasControls: true,
        hasBorders: true,
      });

      canvas.add(qrImage);
      canvas.renderAll();

      if (!this.qrObjects.has(pageNumber)) {
        this.qrObjects.set(pageNumber, []);
      }
      this.qrObjects.get(pageNumber)!.push(qrImage);

      qrImage.on('modified', () => this.onQrModified(pageNumber, qrImage));
    } catch (error: any) {
      if (!environment.production) {
        console.error('Error al cargar QR existente:', error);
      }
    }
  }

  /**
   * Callback cuando un QR es modificado
   */
  private onQrModified(pageNumber?: number, qrImage?: FabricImage): void {
    // Si se proporciona página específica, renderizar solo ese canvas
    if (pageNumber !== undefined) {
      const canvas = this.fabricCanvases.get(pageNumber);
      if (canvas) {
        canvas.renderAll();
      }
    } else {
      // Modo compatibilidad: renderizar canvas actual
      const canvas = this.fabricCanvasInstance;
      if (canvas) {
        canvas.renderAll();
      }
    }
  }

  /**
   * Callback cuando un QR es removido
   */
  private onQrRemoved(pageNumber: number, qrImage: FabricImage): void {
    const qrs = this.qrObjects.get(pageNumber);
    if (qrs) {
      const index = qrs.indexOf(qrImage);
      if (index > -1) {
        qrs.splice(index, 1);
      }
    }
  }

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
