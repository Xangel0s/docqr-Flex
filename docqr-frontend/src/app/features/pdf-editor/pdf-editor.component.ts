import { Component, OnInit, ViewChild, ElementRef, AfterViewInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient, HttpClientModule } from '@angular/common/http';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { DocqrService, Document } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
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
  imports: [CommonModule, HttpClientModule, RouterModule, HeaderComponent, SidebarComponent],
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
  
  // URLs
  pdfUrl: string = '';
  qrImageUrl: string = '';
  
  // Fabric.js
  private fabricCanvasInstance: Canvas | null = null;
  private pdfObject: FabricImage | null = null; // PDF como objeto bloqueado
  private qrObject: FabricImage | null = null;
  
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
    private http: HttpClient
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
    // Verificar múltiples veces si el canvas está disponible
    if (!this.fabricCanvas?.nativeElement) {
      console.warn('Canvas de Fabric.js no disponible aún, reintentando...');
      // Reintentar después de un breve delay
      setTimeout(() => {
        if (this.fabricCanvas?.nativeElement) {
          this.initFabricCanvas();
        } else {
          console.error('No se pudo encontrar el canvas de Fabric.js después de varios intentos');
        }
      }, 100);
      return;
    }

    // Verificar que no esté ya inicializado
    if (this.fabricCanvasInstance) {
      console.log('Canvas de Fabric.js ya está inicializado');
      return;
    }

    try {
      const canvasElement = this.fabricCanvas.nativeElement;
      console.log('Inicializando canvas de Fabric.js:', {
        elementExists: !!canvasElement,
        elementVisible: canvasElement.offsetWidth > 0 && canvasElement.offsetHeight > 0
      });

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

      // Eventos de Fabric.js
      this.fabricCanvasInstance.on('object:modified', () => {
        this.onQrModified();
      });

      this.fabricCanvasInstance.on('object:moving', (e) => {
        this.constrainQrToCanvas(e);
      });

      this.fabricCanvasInstance.on('object:scaling', (e) => {
        this.constrainQrSize(e);
      });

      console.log('Canvas de Fabric.js inicializado correctamente:', {
        width: this.fabricCanvasInstance.width,
        height: this.fabricCanvasInstance.height
      });
    } catch (error: any) {
      console.error('Error al inicializar canvas de Fabric.js:', error);
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

      // URLs
      this.pdfUrl = this.document.pdf_url || '';
      this.qrImageUrl = this.document.qr_image_url || '';

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
      console.error('Error al cargar documento:', error);
      this.notificationService.showError('Error al cargar el documento');
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

    console.log('Iniciando renderizado de PDF como fondo:', {
      pdfUrl: this.pdfUrl
    });

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

      // Limpiar canvas temporal
      tempCtx.fillStyle = 'white';
      tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);

      // Cargar PDF solo si no está cargado o cambió la URL
      if (!this.pdfDoc || this.pdfDoc._transport?.url !== this.pdfUrl) {
        const loadingTask = pdfjsLib.getDocument(this.pdfUrl);
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

      // Convertir canvas temporal a imagen (data URL)
      const pdfImageUrl = tempCanvas.toDataURL('image/png');

      // Eliminar PDF anterior si existe
      if (this.pdfObject) {
        this.fabricCanvasInstance.remove(this.pdfObject);
        this.pdfObject = null;
      }

      // Cargar imagen del PDF como objeto bloqueado en Fabric.js
      const pdfImage = await FabricImage.fromURL(pdfImageUrl, {
        crossOrigin: 'anonymous'
      });

      // Configurar PDF como objeto bloqueado (no interactivo)
      pdfImage.set({
        left: 0,
        top: 0,
        selectable: false,
        evented: false,
        hasControls: false,
        hasBorders: false,
        lockMovementX: true,
        lockMovementY: true,
        lockRotation: true,
        lockScalingX: true,
        lockScalingY: true,
        excludeFromExport: false // Incluir en exportación
      });

      // Agregar PDF al canvas (se agregará primero, quedando en el fondo)
      this.fabricCanvasInstance.add(pdfImage);
      this.pdfObject = pdfImage;

      // Renderizar para mostrar el PDF
      this.fabricCanvasInstance.renderAll();

      console.log('PDF renderizado como objeto en Fabric.js exitosamente:', {
        original: { width: originalViewport.width, height: originalViewport.height },
        scaled: { width: scaledViewport.width, height: scaledViewport.height },
        canvas: { width: tempCanvas.width, height: tempCanvas.height },
        offset: { x: offsetX, y: offsetY },
        scale
      });

    } catch (error: any) {
      console.error('Error al renderizar PDF como fondo:', error);
      throw error;
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
      console.error('URL de imagen QR no disponible');
      return;
    }

    console.log('Cargando QR en Fabric.js:', {
      qrImageUrl: this.qrImageUrl,
      canvasInitialized: !!this.fabricCanvasInstance
    });

    try {
      // Cargar imagen QR usando fromURL con Promise
      const img = await FabricImage.fromURL(this.qrImageUrl, {
        crossOrigin: 'anonymous'
      });

      if (!this.fabricCanvasInstance) {
        console.error('Canvas de Fabric.js se perdió durante la carga');
        return;
      }

      console.log('Imagen QR cargada:', {
        width: img.width,
        height: img.height
      });

      // Si ya existe un QR, eliminarlo
      if (this.qrObject) {
        this.fabricCanvasInstance.remove(this.qrObject);
      }

      // Configurar posición inicial o restaurar posición guardada
      // IMPORTANTE: Usar las coordenadas EXACTAS guardadas, sin redondear ni ajustar
      let x = 50;
      let y = 50;
      let width = 100;
      let height = 100;

      // Si hay posición guardada, restaurarla EXACTAMENTE como está guardada
      if (this.document?.qr_position) {
        const pos = this.document.qr_position;
        // Usar valores exactos sin redondear para mantener la posición precisa
        x = pos.x ?? 50;
        y = pos.y ?? 50;
        width = pos.width ?? 100;
        height = pos.height ?? 100;
        
        console.log('Restaurando posición guardada (EXACTA):', {
          saved: pos,
          restored: { x, y, width, height }
        });
      } else {
        console.log('No hay posición guardada, usando posición inicial por defecto');
      }

      // Configurar objeto QR con las coordenadas exactas
      img.set({
        left: x,  // Usar valor exacto sin redondear
        top: y,   // Usar valor exacto sin redondear
        scaleX: width / (img.width || 100),
        scaleY: height / (img.height || 100),
        selectable: true,
        hasControls: true,
        hasBorders: true,
        lockRotation: true, // No permitir rotación (como especificaste)
        lockScalingFlip: true,
        minScaleLimit: 50 / (img.width || 100), // Tamaño mínimo: 50px
        maxScaleLimit: 300 / (img.width || 100) // Tamaño máximo: 300px
      });
      
      // IMPORTANTE: NO llamar a constrainQrToCanvas() aquí
      // El QR debe cargarse en la posición exacta guardada
      // El usuario puede moverlo después si lo desea

      // Agregar QR al canvas (se agregará encima del PDF porque se agrega después)
      // En Fabric.js, los objetos se renderizan en el orden de inserción (último = arriba)
      this.fabricCanvasInstance.add(img);
      this.fabricCanvasInstance.setActiveObject(img);
      this.qrObject = img;

      // Renderizar todo (el PDF ya está en el fondo porque se agregó primero)
      this.fabricCanvasInstance.renderAll();

      console.log('QR cargado en Fabric.js:', {
        position: { x, y, width, height },
        scale: { x: img.scaleX, y: img.scaleY },
        canvasSize: { 
          width: this.fabricCanvasInstance.width, 
          height: this.fabricCanvasInstance.height 
        }
      });

    } catch (error: any) {
      console.error('Error al cargar QR en Fabric.js:', error);
      throw error;
    }
  }

  /**
   * Restringir QR dentro de los límites del canvas
   */
  private constrainQrToCanvas(e: any): void {
    if (!this.qrObject || !this.fabricCanvasInstance) return;

    const obj = e.target as FabricImage;
    if (!obj) return;

    const canvas = this.fabricCanvasInstance;

    // Calcular dimensiones reales del QR usando getBoundingRect()
    // Esto es más preciso que multiplicar width * scaleX manualmente
    const boundingRect = obj.getBoundingRect();
    const qrWidth = boundingRect.width;
    const qrHeight = boundingRect.height;

    // IMPORTANTE: Restringir el QR al área REAL del PDF (no al canvas completo)
    // El PDF puede ser más pequeño que A4 y está centrado con offsets
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

    // Aplicar restricciones
    let needsUpdate = false;
    let newLeft = obj.left || 0;
    let newTop = obj.top || 0;

    if (newLeft < minX) {
      newLeft = minX;
      needsUpdate = true;
    }
    if (newLeft > maxX) {
      newLeft = maxX;
      needsUpdate = true;
    }
    if (newTop < minY) {
      newTop = minY;
      needsUpdate = true;
    }
    if (newTop > maxY) {
      newTop = maxY;
      needsUpdate = true;
    }

    if (needsUpdate) {
      obj.set({
        left: newLeft,
        top: newTop
      });
      this.fabricCanvasInstance.renderAll();
    }
  }

  /**
   * Restringir tamaño del QR
   */
  private constrainQrSize(e: any): void {
    if (!this.qrObject) return;

    const obj = e.target as FabricImage;
    if (!obj) return;

    const currentWidth = obj.width! * obj.scaleX!;
    const currentHeight = obj.height! * obj.scaleY!;

    // Validar tamaño mínimo y máximo
    if (currentWidth < 50 || currentHeight < 50) {
      const minScale = 50 / Math.max(obj.width!, obj.height!);
      obj.set({
        scaleX: Math.max(minScale, obj.scaleX!),
        scaleY: Math.max(minScale, obj.scaleY!)
      });
    }

    if (currentWidth > 300 || currentHeight > 300) {
      const maxScale = 300 / Math.max(obj.width!, obj.height!);
      obj.set({
        scaleX: Math.min(maxScale, obj.scaleX!),
        scaleY: Math.min(maxScale, obj.scaleY!)
      });
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
      
      // IMPORTANTE: Usar las coordenadas DIRECTAS del objeto (left, top)
      // NO usar getBoundingRect() para la posición, solo para dimensiones
      // getBoundingRect() puede incluir offsets que no queremos
      let left = obj.left || 0;
      let top = obj.top || 0;
      
      // Usar getBoundingRect() SOLO para obtener dimensiones reales después del escalado
      const boundingRect = obj.getBoundingRect();
      const width = boundingRect.width;
      const height = boundingRect.height;
      
      console.log('Coordenadas del objeto Fabric.js:', {
        obj_left: obj.left,
        obj_top: obj.top,
        boundingRect_left: boundingRect.left,
        boundingRect_top: boundingRect.top,
        diferencia: {
          x: (obj.left || 0) - boundingRect.left,
          y: (obj.top || 0) - boundingRect.top
        },
        usando_coordenadas: 'obj.left y obj.top (directas del objeto)'
      });
      
      console.log('Dimensiones del QR (escalado):', {
        boundingRect: { width: boundingRect.width, height: boundingRect.height },
        objDimensions: { 
          width: obj.width, 
          height: obj.height, 
          scaleX: obj.scaleX, 
          scaleY: obj.scaleY 
        },
        calculated: {
          width: (obj.width || 100) * (obj.scaleX || 1),
          height: (obj.height || 100) * (obj.scaleY || 1)
        }
      });

      // Validar que el QR esté completamente dentro del canvas antes de guardar
      const canvasWidth = this.fabricCanvasInstance.width!;
      const canvasHeight = this.fabricCanvasInstance.height!;

      // IMPORTANTE: Convertir coordenadas del espacio del canvas (595x842) al espacio REAL del PDF
      // El PDF puede ser más pequeño que A4 y está centrado con offsets
      // Necesitamos convertir las coordenadas del QR al espacio real del PDF
      
      // Obtener dimensiones reales del PDF (sin escalar)
      const pdfRealWidth = this.pdfDimensions.originalWidth || this.STANDARD_PDF_WIDTH;
      const pdfRealHeight = this.pdfDimensions.originalHeight || this.STANDARD_PDF_HEIGHT;
      const pdfScale = this.pdfDimensions.scale || 1.0;
      const pdfOffsetX = this.pdfDimensions.offsetX || 0;
      const pdfOffsetY = this.pdfDimensions.offsetY || 0;
      
      // Calcular dimensiones escaladas del PDF en el canvas
      const pdfScaledWidth = pdfRealWidth * pdfScale;
      const pdfScaledHeight = pdfRealHeight * pdfScale;
      
      // Convertir coordenadas del QR del espacio del canvas al espacio real del PDF
      // 1. Restar el offset (el PDF está centrado)
      let qrXInCanvas = left - pdfOffsetX;
      let qrYInCanvas = top - pdfOffsetY;
      
      // 2. Convertir del espacio escalado al espacio real del PDF
      let qrXInRealPdf = (qrXInCanvas / pdfScale);
      let qrYInRealPdf = (qrYInCanvas / pdfScale);
      let qrWidthInRealPdf = width / pdfScale;
      let qrHeightInRealPdf = height / pdfScale;
      
      // 3. Validar que el QR esté dentro del área real del PDF
      if (qrXInCanvas < 0 || qrYInCanvas < 0 || 
          qrXInCanvas + width > pdfScaledWidth || 
          qrYInCanvas + height > pdfScaledHeight) {
        this.notificationService.showError('El QR está fuera del área del documento. Ajusta la posición manualmente.');
        this.saving = false;
        return;
      }
      
      // 4. Convertir al espacio estándar (595x842) para enviar al backend
      // El backend espera coordenadas en el espacio estándar y las convertirá al tamaño real
      const standardX = (qrXInRealPdf / pdfRealWidth) * this.STANDARD_PDF_WIDTH;
      const standardY = (qrYInRealPdf / pdfRealHeight) * this.STANDARD_PDF_HEIGHT;
      const standardWidth = (qrWidthInRealPdf / pdfRealWidth) * this.STANDARD_PDF_WIDTH;
      const standardHeight = (qrHeightInRealPdf / pdfRealHeight) * this.STANDARD_PDF_HEIGHT;
      
      const position = {
        x: Math.round(standardX * 100) / 100, // Redondear a 2 decimales
        y: Math.round(standardY * 100) / 100,
        width: Math.round(standardWidth * 100) / 100,
        height: Math.round(standardHeight * 100) / 100
      };
      
      console.log('=== GUARDANDO POSICIÓN (Fabric.js) ===');
      console.log('Coordenadas del objeto Fabric.js (canvas):', {
        obj_left: obj.left,
        obj_top: obj.top,
        boundingRect: { width: boundingRect.width, height: boundingRect.height }
      });
      console.log('Dimensiones del PDF real:', {
        real: { width: pdfRealWidth, height: pdfRealHeight },
        scaled: { width: pdfScaledWidth, height: pdfScaledHeight },
        scale: pdfScale,
        offset: { x: pdfOffsetX, y: pdfOffsetY }
      });
      console.log('Conversión de coordenadas:', {
        canvas: { x: left, y: top, width, height },
        pdf_scaled: { x: qrXInCanvas, y: qrYInCanvas, width, height },
        pdf_real: { x: qrXInRealPdf, y: qrYInRealPdf, width: qrWidthInRealPdf, height: qrHeightInRealPdf },
        standard: position
      });
      console.log('Dimensiones del canvas:', { width: canvasWidth, height: canvasHeight });
      console.log('Validación límites:', {
        x: position.x >= 0 && position.x + position.width <= canvasWidth,
        y: position.y >= 0 && position.y + position.height <= canvasHeight,
        dentro_area_segura: position.x >= this.SAFE_MARGIN && 
                          position.y >= this.SAFE_MARGIN && 
                          position.x + position.width <= canvasWidth - this.SAFE_MARGIN && 
                          position.y + position.height <= canvasHeight - this.SAFE_MARGIN
      });
      console.log('Estas coordenadas se enviarán EXACTAMENTE al backend sin modificar');

      // Validar tamaño
      if (position.width < 50 || position.width > 300 || position.height < 50 || position.height > 300) {
        this.notificationService.showError('El tamaño del QR debe estar entre 50px y 300px');
        this.saving = false;
        return;
      }

      // Validar que esté completamente dentro del área real del PDF (con margen opcional)
      // Convertir el SAFE_MARGIN del espacio estándar al espacio real del PDF
      const safeMarginInRealPdf = (this.SAFE_MARGIN / this.STANDARD_PDF_WIDTH) * pdfRealWidth;
      const safeMarginYInRealPdf = (this.SAFE_MARGIN / this.STANDARD_PDF_HEIGHT) * pdfRealHeight;
      
      // Validar en el espacio real del PDF
      if (qrXInRealPdf < safeMarginInRealPdf || qrYInRealPdf < safeMarginYInRealPdf || 
          qrXInRealPdf + qrWidthInRealPdf > pdfRealWidth - safeMarginInRealPdf || 
          qrYInRealPdf + qrHeightInRealPdf > pdfRealHeight - safeMarginYInRealPdf) {
        if (this.SAFE_MARGIN > 0) {
          this.notificationService.showError(`El QR debe estar dentro del área segura (margen de ${this.SAFE_MARGIN}px desde los bordes). Ajusta la posición.`);
        } else {
          this.notificationService.showError('El QR está fuera del área del documento. Ajusta la posición.');
        }
        this.saving = false;
        return;
      }

      // Usar el backend directamente (FPDI/TCPDF) - MÁS CONFIABLE
      // El backend garantiza que solo se procese la primera página y no cree páginas adicionales
      this.savePositionBackend(position);

    } catch (error: any) {
      console.error('Error al guardar posición:', error);
      this.notificationService.showError('Error al guardar la posición');
      this.saving = false;
    }
  }

  /**
   * Embebir QR en PDF usando pdf-lib (método exacto y profesional)
   * Crea un nuevo PDF solo con la primera página para evitar páginas adicionales
   */
  private async embedQrWithPdfLib(position: { x: number; y: number; width: number; height: number }): Promise<void> {
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

      console.log('Dimensiones reales del PDF:', { 
        width: pageWidth, 
        height: pageHeight,
        totalPages: sourcePages.length
      });

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

      // Obtener referencia a la página en el nuevo documento
      const pages = newPdfDoc.getPages();
      const firstPage = pages[0];

      console.log('Nuevo PDF creado con 1 página (contenido idéntico al original):', {
        pageCount: newPdfDoc.getPageCount(),
        pageSize: { width: pageWidth, height: pageHeight }
      });

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
        console.error('QR fuera del área segura en embedQrWithPdfLib:', {
          pdfX, pdfY, pdfWidth, pdfHeight,
          minX, minY, maxX, maxY
        });
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
        console.error('QR fuera de límites del PDF (CRÍTICO):', {
          pdfX, pdfY, pdfWidth, pdfHeight,
          pageWidth: Math.floor(pageWidth), 
          pageHeight: Math.floor(pageHeight),
          safetyMargin,
          bounds: {
            x: pdfX >= safetyMargin,
            y: pdfY >= safetyMargin,
            right: pdfX + pdfWidth <= finalMaxX,
            bottom: pdfY + pdfHeight <= finalMaxY
          },
          calculated: {
            maxX: finalMaxX,
            maxY: finalMaxY,
            qrRight: pdfX + pdfWidth,
            qrBottom: pdfY + pdfHeight
          }
        });
        this.notificationService.showError('El QR está fuera de los límites del documento. Por favor, ajusta la posición.');
        this.saving = false;
        return;
      }

      console.log('Coordenadas convertidas para pdf-lib:', {
        canvas: position,
        pdf: { x: pdfX, y: pdfY, width: pdfWidth, height: pdfHeight },
        pageSize: { width: pageWidth, height: pageHeight },
        withinBounds: pdfX >= 0 && pdfY >= 0 && pdfX + pdfWidth <= pageWidth && pdfY + pdfHeight <= pageHeight
      });

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

      // Verificar inmediatamente después de dibujar que no se crearon páginas adicionales
      const pageCountAfterDraw = newPdfDoc.getPageCount();
      console.log('Páginas después de drawImage:', pageCountAfterDraw);
      
      if (pageCountAfterDraw > 1) {
        console.error('ERROR CRÍTICO: pdf-lib creó páginas adicionales después de drawImage:', {
          pageCount: pageCountAfterDraw,
          coordinates: { 
            x: pdfX, 
            y: pdfY, 
            width: pdfWidth, 
            height: pdfHeight,
            bottom: pdfY + pdfHeight
          },
          pageSize: { 
            width: pageWidth, 
            height: pageHeight 
          },
          withinBounds: pdfY + pdfHeight <= pageHeight
        });
        
        // Eliminar páginas adicionales inmediatamente
        while (newPdfDoc.getPageCount() > 1) {
          const pageToRemove = newPdfDoc.getPageCount() - 1;
          console.warn('Eliminando página adicional creada por drawImage:', pageToRemove);
          newPdfDoc.removePage(pageToRemove);
        }
        
        // Verificar que se eliminaron correctamente
        const pageCountAfterRemoval = newPdfDoc.getPageCount();
        if (pageCountAfterRemoval > 1) {
          console.error('ERROR: No se pudieron eliminar todas las páginas adicionales');
          this.notificationService.showError('Error al procesar el PDF. Por favor, intenta ajustar la posición del QR.');
          this.saving = false;
          return;
        }
      }

      // Verificar que el documento solo tenga 1 página antes de guardar
      const pageCountBeforeSave = newPdfDoc.getPageCount();
      if (pageCountBeforeSave !== 1) {
        console.error('ERROR CRÍTICO: El PDF tiene más de 1 página antes de guardar:', {
          pageCount: pageCountBeforeSave,
          coordinates: { x: pdfX, y: pdfY, width: pdfWidth, height: pdfHeight },
          pageSize: { width: pageWidth, height: pageHeight },
          qrBottom: pdfY + pdfHeight,
          pageHeight: pageHeight
        });
        
        // Eliminar páginas adicionales si las hay
        while (newPdfDoc.getPageCount() > 1) {
          const pageToRemove = newPdfDoc.getPageCount() - 1;
          console.warn('Eliminando página adicional:', pageToRemove);
          newPdfDoc.removePage(pageToRemove);
        }
      }

      // Guardar PDF modificado (solo con 1 página garantizada)
      const modifiedPdfBytes = await newPdfDoc.save();
      
      // Verificar el PDF guardado cargándolo de nuevo
      const verifyPdfDoc = await PDFDocument.load(modifiedPdfBytes);
      const finalPageCount = verifyPdfDoc.getPageCount();
      
      console.log('=== VERIFICACIÓN FINAL DEL PDF ===');
      console.log('Páginas antes de guardar:', pageCountBeforeSave);
      console.log('Páginas después de guardar (verificación):', finalPageCount);
      console.log('Coordenadas del QR:', {
        x: pdfX,
        y: pdfY,
        width: pdfWidth,
        height: pdfHeight,
        bottom: pdfY + pdfHeight,
        pageHeight: pageHeight,
        withinBounds: pdfY + pdfHeight <= pageHeight
      });
      
      if (finalPageCount > 1) {
        console.error('ERROR CRÍTICO: El PDF guardado tiene', finalPageCount, 'páginas después de guardar');
        this.notificationService.showError(`Error: El PDF generado tiene ${finalPageCount} páginas. Por favor, ajusta la posición del QR más arriba.`);
        this.saving = false;
        return;
      }

      // Enviar al backend
      const pdfBlob = new Blob([modifiedPdfBytes], { type: 'application/pdf' });
      const pdfFile = new File([pdfBlob], 'modified.pdf', { type: 'application/pdf' });
      
      const formData = new FormData();
      formData.append('qr_id', this.qrId);
      formData.append('pdf', pdfFile, 'modified.pdf');
      formData.append('x', position.x.toString());
      formData.append('y', position.y.toString());
      formData.append('width', position.width.toString());
      formData.append('height', position.height.toString());

      this.http.put(`${environment.apiUrl}/embed-pdf`, formData).subscribe({
        next: (response: any) => {
          if (response.success) {
            this.notificationService.showSuccess('QR embebido exitosamente usando Fabric.js + pdf-lib');
            this.loadDocument(); // Recargar para mostrar el PDF final
            this.saving = false;
          } else {
            this.notificationService.showError(response.message || 'Error al guardar PDF');
            this.saving = false;
          }
        },
        error: (error: any) => {
          console.error('Error al guardar PDF:', error);
          this.notificationService.showError('Error al guardar PDF. Usando método alternativo...');
          // Fallback al método anterior
          this.savePositionBackend(position);
        }
      });

    } catch (error: any) {
      console.error('Error al procesar PDF con pdf-lib:', error);
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
    console.log('=== GUARDANDO POSICIÓN USANDO BACKEND (FPDI/TCPDF) ===');
    console.log('Coordenadas enviadas al backend:', position);
    console.log('El backend procesará solo la primera página del PDF original');
    
    this.docqrService.embedQr(this.qrId, position).subscribe({
      next: (response) => {
        if (response.success) {
          console.log('QR embebido exitosamente por el backend');
          console.log('Posición guardada:', position);
          this.notificationService.showSuccess('QR embebido exitosamente');
          
          // Actualizar la posición en el objeto local con las coordenadas EXACTAS guardadas
          // Esto previene que se restaure una posición anterior y mantiene el QR donde el usuario lo colocó
          if (this.qrObject) {
            console.log('Actualizando posición del QR en el editor con coordenadas EXACTAS:', {
              position_guardada: position,
              obj_actual: {
                left: this.qrObject.left,
                top: this.qrObject.top,
                width: this.qrObject.width,
                height: this.qrObject.height
              }
            });
            
            // Usar las coordenadas EXACTAS sin modificar
            this.qrObject.set({
              left: position.x,  // Coordenada exacta guardada
              top: position.y,   // Coordenada exacta guardada
              scaleX: position.width / (this.qrObject.width || 100),
              scaleY: position.height / (this.qrObject.height || 100)
            });
            
            // Forzar renderizado para mostrar la posición actualizada
            this.fabricCanvasInstance?.renderAll();
            
            console.log('QR actualizado en el editor:', {
              nueva_posicion: {
                left: this.qrObject.left,
                top: this.qrObject.top
              }
            });
          }
          
          // Actualizar el documento local con la nueva posición EXACTA y final_pdf_url
          if (this.document) {
            this.document.qr_position = {
              x: position.x,
              y: position.y,
              width: position.width,
              height: position.height
            };
            this.document.status = 'completed';
            // IMPORTANTE: Actualizar final_pdf_url si viene en la respuesta
            if (response.data?.final_pdf_url) {
              this.document.final_pdf_url = response.data.final_pdf_url;
              console.log('final_pdf_url actualizado:', this.document.final_pdf_url);
            }
            console.log('Documento local actualizado con posición:', this.document.qr_position);
          }
          
          // NO recargar el documento completo para evitar restaurar posición anterior
          // this.loadDocument(); // Comentado para evitar recargar y restaurar posición
          
          this.saving = false;
        } else {
          this.notificationService.showError(response.message || 'Error al embebir QR');
          this.saving = false;
        }
      },
      error: (error: any) => {
        console.error('Error al embebir QR en el backend:', error);
        this.notificationService.showError('Error al embebir QR en el PDF');
        this.saving = false;
      }
    });
  }

  /**
   * Cancelar y volver
   */
  cancel(): void {
    if (confirm('¿Estás seguro de que deseas cancelar? Los cambios no se guardarán.')) {
      this.router.navigate(['/documents']);
    }
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
   * Descargar imagen QR
   */
  downloadQrImage(): void {
    if (!this.qrImageUrl) {
      this.notificationService.showError('No hay imagen QR disponible');
      return;
    }

    const link = document.createElement('a');
    link.href = this.qrImageUrl;
    link.download = `qr-${this.qrId}.png`;
    link.click();
  }

  /**
   * Descargar PDF con QR embebido
   */
  downloadPdfWithQr(): void {
    if (!this.document?.final_pdf_url) {
      this.notificationService.showError('El PDF con QR aún no está disponible. Guarda primero la posición.');
      return;
    }

    const link = document.createElement('a');
    link.href = this.document.final_pdf_url;
    link.download = `${this.document.original_filename}`;
    link.click();
  }
}
