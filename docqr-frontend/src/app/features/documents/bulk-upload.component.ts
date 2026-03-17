import { Component, HostListener, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { Router, RouterModule } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { DocqrService, Document } from '../../core/services/docqr.service';
import {
  BulkDraftRow,
  BulkDraftState,
  BulkEmbedStatus,
  BulkRowProcessResult,
  BulkRowStatus,
  BulkReviewStatus,
  BulkUploadDraftService
} from '../../core/services/bulk-upload-draft.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';
import { PdfPreviewModalComponent } from '../../shared/components/pdf-preview-modal/pdf-preview-modal.component';

interface BulkTemplatePosition {
  x: number;
  y: number;
  width: number;
  height: number;
  page_number: number;
}

/**
 * Módulo de carga masiva para documentos IN
 */
@Component({
  selector: 'app-bulk-upload',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, HeaderComponent, SidebarComponent, PdfPreviewModalComponent],
  templateUrl: './bulk-upload.component.html',
  styleUrls: ['./bulk-upload.component.scss']
})
export class BulkUploadComponent implements OnInit, OnDestroy {
  sidebarOpen: boolean = false;
  currentStep: number = 1; // 1: Config, 2: Sample, 3: Process
  rowCountInput: number = 1;
  generatedCount: number = 0;
  rows: BulkDraftRow[] = [];
  storedDraft: BulkDraftState | null = null;
  batchDragOver: boolean = false;
  isSavingDraft: boolean = false;
  isProcessing: boolean = false;
  isRefreshingTemplate: boolean = false;
  isApplyingTemplate: boolean = false;
  templateSourceRowId: string | null = null;
  templatePosition: BulkTemplatePosition | null = null;
  templateSourceDocument: Document | null = null;
  previewModalOpen: boolean = false;
  previewPdfUrl: string = '';
  previewDocumentName: string = '';
  editorModalOpen: boolean = false;
  editorFrameUrl: SafeResourceUrl | null = null;
  editorDocumentName: string = '';
  editorRowId: string | null = null;
  editorModalMode: 'sample' | 'edit' = 'sample';
  readonly maxRows: number = 50;
  readonly maxFileSize = 500 * 1024 * 1024;
  readonly maxEmissionDate: string = this.getTodayDateString();
  
  // UX State
  overallProgress: number = 0;
  isBatchFinished: boolean = false;
  
  private readonly codeCheckTimeouts = new Map<string, ReturnType<typeof setTimeout>>();
  private readonly rowsPendingEditorSync = new Set<string>();
  private awaitingSampleRefresh: boolean = false;

  constructor(
    private docqrService: DocqrService,
    private draftService: BulkUploadDraftService,
    private notificationService: NotificationService,
    private router: Router,
    private sanitizer: DomSanitizer
  ) {}

  async ngOnInit(): Promise<void> {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }

    try {
      this.storedDraft = await this.draftService.loadDraft();
      this.syncTemplateSource();
    } catch (error) {
      console.error('No se pudo cargar el borrador masivo.', error);
      this.notificationService.showWarning('No se pudo leer el borrador guardado del módulo masivo.');
    }
  }

  ngOnDestroy(): void {
    this.clearAllCodeCheckTimeouts();
  }

  /**
   * Activar resaltado visual al arrastrar PDFs sobre el lote
   */
  onBatchDragOver(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();

    if (this.isProcessing) {
      return;
    }

    this.batchDragOver = true;
  }

  /**
   * Quitar resaltado visual al salir del área masiva
   */
  onBatchDragLeave(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.batchDragOver = false;
  }

  /**
   * Aceptar varios PDFs por drag and drop en el lote
   */
  onBatchDrop(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.batchDragOver = false;

    if (this.isProcessing) {
      return;
    }

    const files = Array.from(event.dataTransfer?.files ?? []);
    this.processBatchFiles(files);
  }

  /**
   * Sincronizar cambios hechos en el editor al volver a la pestaña del lote
   */
  @HostListener('window:focus')
  onWindowFocus(): void {
    if (this.awaitingSampleRefresh) {
      void this.refreshTemplateFromSample(true);
    }

    if (this.rowsPendingEditorSync.size > 0) {
      void this.syncRowsAfterManualEdit();
    }
  }

  /**
   * Recibir eventos del editor embebido en modal
   */
  @HostListener('window:message', ['$event'])
  onWindowMessage(event: MessageEvent): void {
    if (event.origin !== window.location.origin) {
      return;
    }

    const data = event.data;

    if (!data || data.source !== 'docqr-editor') {
      return;
    }

    if (data.type === 'saved' && data.qrId) {
      const row = this.uploadedRows.find((currentRow) => currentRow.result?.qr_id === data.qrId);

      if (row) {
        this.rowsPendingEditorSync.add(row.rowId);

        if (row.rowId === this.sampleRow?.rowId) {
          this.awaitingSampleRefresh = true;
          void this.refreshTemplateFromSample(true);
        } else {
          void this.syncRowsAfterManualEdit();
        }
      }

      this.closeEditorModal();
      return;
    }

    if (data.type === 'closed') {
      this.closeEditorModal();
    }
  }

  /**
   * Navegar a un paso específico en el flujo de inyección
   */
  goToStep(step: number): void {
    if (this.isProcessing) return;
    
    // Validaciones básicas de navegación
    if (step === 2 && this.rows.length === 0) {
      this.notificationService.showWarning('Primero debes generar filas o cargar archivos.');
      return;
    }
    
    if (step === 3 && !this.templatePosition) {
      this.notificationService.showWarning('Primero debes configurar el QR en la muestra (Paso 2).');
      return;
    }

    this.currentStep = step;
    
    if (step === 2 || step === 3) {
      this.syncTemplateSource();
    }
  }



  /**
   * Obtener fecha actual en formato YYYY-MM-DD usando hora local
   */
  private getTodayDateString(): string {
    const now = new Date();
    const offset = now.getTimezoneOffset();
    const localDate = new Date(now.getTime() - offset * 60000);
    return localDate.toISOString().split('T')[0];
  }

  /**
   * Reaccionar al cambio manual de código en una fila
   */
  onCodeChanged(row: BulkDraftRow): void {
    this.markRowAsDirty(row);
    row.code = this.normalizeCode(row.code);
    this.refreshCodeValidation([row]);
    void this.persistCurrentState();
  }

  /**
   * Reaccionar al cambio manual de fecha en una fila
   */
  onEmissionDateChanged(row: BulkDraftRow): void {
    this.markRowAsDirty(row);
    this.syncRowStatus(row);
    void this.persistCurrentState();
  }

  /**
   * Crear filas vacías según la cantidad indicada
   */
  generateRows(): void {
    const requestedCount = Number(this.rowCountInput);

    if (!Number.isInteger(requestedCount) || requestedCount < 1 || requestedCount > this.maxRows) {
      this.notificationService.showError(`La cantidad debe estar entre 1 y ${this.maxRows}.`);
      return;
    }

    if (this.hasRowsWithData() && !window.confirm('Se reemplazarán las filas actuales. ¿Deseas continuar?')) {
      return;
    }

    this.rows = Array.from({ length: requestedCount }, () => this.createEmptyRow());
    this.generatedCount = requestedCount;
    this.templateSourceRowId = null;
    this.templatePosition = null;
    this.templateSourceDocument = null;
    this.awaitingSampleRefresh = false;
    this.rowsPendingEditorSync.clear();
    this.closeEditorModal();
  }

  /**
   * Restaurar borrador guardado previamente
   */
  restoreDraft(): void {
    if (!this.storedDraft) {
      return;
    }

    this.rows = this.storedDraft.rows.map((row) => this.cloneRow(row));
    this.generatedCount = this.storedDraft.generatedCount || this.rows.length;
    this.rowCountInput = Math.max(1, Math.min(this.generatedCount || this.rows.length || 1, this.maxRows));
    this.refreshCodeValidation(this.rows);
    this.refreshPdfDuplicateValidation(this.rows);
    this.awaitingSampleRefresh = false;
    this.rowsPendingEditorSync.clear();
    this.syncTemplateSource();
    this.notificationService.showSuccess('Borrador restaurado.');
  }

  /**
   * Descartar borrador guardado previamente
   */
  async discardStoredDraft(): Promise<void> {
    if (!this.storedDraft) {
      return;
    }

    if (!window.confirm('Se eliminará el borrador guardado del navegador. ¿Deseas continuar?')) {
      return;
    }

    try {
      await this.draftService.clearDraft();
      this.storedDraft = null;
      this.notificationService.showInfo('Borrador almacenado eliminado.');
    } catch (error) {
      console.error('No se pudo eliminar el borrador almacenado.', error);
      this.notificationService.showError('No se pudo eliminar el borrador almacenado.');
    }
  }

  /**
   * Agregar una fila manualmente
   */
  addRow(): void {
    if (this.rows.length >= this.maxRows) {
      this.notificationService.showWarning(`Solo se permiten ${this.maxRows} filas por lote.`);
      return;
    }

    this.rows = [...this.rows, this.createEmptyRow()];
    this.generatedCount = this.rows.length;
    this.rowCountInput = this.rows.length;
    this.refreshPdfDuplicateValidation(this.rows);
  }

  /**
   * Eliminar una fila del lote
   */
  removeRow(rowId: string): void {
    this.clearCodeCheckTimeout(rowId);
    this.rows = this.rows.filter((row) => row.rowId !== rowId);
    this.generatedCount = this.rows.length;
    this.rowCountInput = Math.max(1, this.rows.length || 1);
    this.refreshCodeValidation(this.rows);
    this.refreshPdfDuplicateValidation(this.rows);
    this.syncTemplateSource();
    this.rowsPendingEditorSync.delete(rowId);

    if (this.editorRowId === rowId) {
      this.closeEditorModal();
    }
  }


  /**
   * Manejar selección de archivo PDF por fila
   */
  onFileSelected(row: BulkDraftRow, event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files && input.files.length > 0 ? input.files[0] : null;

    if (!file) {
      return;
    }

    this.applyFileToRow(row, file);
    this.refreshPdfDuplicateValidation(this.rows);
    input.value = '';
  }

  /**
   * Cargar varios PDFs y repartirlos automáticamente por fila
   */
  onBatchFilesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    this.processBatchFiles(files);
    input.value = '';
  }

  /**
   * Procesar una selección múltiple y repartirla entre las filas del lote
   */
  private processBatchFiles(files: File[]): void {
    if (!files.length) {
      return;
    }

    if (files.length > this.maxRows) {
      this.notificationService.showError(`Solo se permiten ${this.maxRows} archivos por lote.`);
      return;
    }

    if (!this.rows.length) {
      this.rows = Array.from({ length: files.length }, () => this.createEmptyRow());
    } else if (files.length > this.rows.length) {
      this.rows = [
        ...this.rows,
        ...Array.from({ length: files.length - this.rows.length }, () => this.createEmptyRow())
      ];
    }

    this.generatedCount = this.rows.length;
    this.rowCountInput = this.rows.length;

    let assignedCount = 0;
    let invalidCount = 0;

    files.forEach((file, index) => {
      const validationMessage = this.applyFileToRow(this.rows[index], file);

      if (validationMessage) {
        invalidCount++;
      } else {
        assignedCount++;
      }
    });

    this.refreshPdfDuplicateValidation(this.rows);
    this.syncTemplateSource();

    if (assignedCount > 0) {
      this.notificationService.showSuccess(`Se asignaron ${assignedCount} PDF(s) al lote.`);
    }

    if (invalidCount > 0) {
      this.notificationService.showWarning(`${invalidCount} archivo(s) no pudieron cargarse. Revisa las filas marcadas.`);
    }

    // Avanzar al paso de muestra si hay archivos cargados
    if (assignedCount > 0) {
      this.currentStep = 2;
    }
  }

  /**
   * Remover archivo adjunto de una fila
   */
  removeSelectedFile(row: BulkDraftRow): void {
    this.markRowAsDirty(row);
    row.file = null;
    row.fileName = '';
    row.fileType = '';
    row.fileLastModified = 0;
    row.pdfDuplicateError = null;
    this.syncRowStatus(row);
    this.refreshPdfDuplicateValidation(this.rows);
  }

  /**
   * Guardar borrador actual en IndexedDB
   */
  async saveDraft(): Promise<void> {
    if (!this.rows.length) {
      this.notificationService.showWarning('No hay filas para guardar como borrador.');
      return;
    }

    this.isSavingDraft = true;

    try {
      const state = this.buildDraftState(this.rows);
      await this.draftService.saveDraft(state);
      this.storedDraft = state;
      this.notificationService.showSuccess('Borrador guardado en este navegador.');
    } catch (error) {
      console.error('No se pudo guardar el borrador.', error);
      this.notificationService.showError('No se pudo guardar el borrador del lote.');
    } finally {
      this.isSavingDraft = false;
    }
  }

  /**
   * Limpiar el formulario y el borrador persistido
   */
  async clearDraft(): Promise<void> {
    if (!this.rows.length && !this.storedDraft) {
      this.notificationService.showInfo('No hay borrador para limpiar.');
      return;
    }

    if (!window.confirm('Se eliminarán las filas actuales y el borrador almacenado. ¿Deseas continuar?')) {
      return;
    }

    try {
      await this.draftService.clearDraft();
      this.storedDraft = null;
      this.rows = [];
      this.generatedCount = 0;
      this.rowCountInput = 1;
      this.templateSourceRowId = null;
      this.templatePosition = null;
      this.templateSourceDocument = null;
      this.awaitingSampleRefresh = false;
      this.rowsPendingEditorSync.clear();
      this.closeEditorModal();
      this.closePreviewModal();
      this.notificationService.showInfo('Borrador limpiado.');
    } catch (error) {
      console.error('No se pudo limpiar el borrador.', error);
      this.notificationService.showError('No se pudo limpiar el borrador.');
    }
  }

  /**
   * Preparar el documento muestra y abrir el editor visual embebido
   */
  async prepareSampleRow(): Promise<void> {
    const row = this.sampleRow;

    if (!row) {
      this.notificationService.showWarning('Primero debes generar al menos una fila.');
      return;
    }

    if (this.isProcessing) {
      return;
    }

    this.refreshCodeValidation([row]);
    this.refreshPdfDuplicateValidation([row]);

    if (!row.result?.qr_id && !this.validateRowsBeforeProcessing([row])) {
      this.notificationService.showError('Corrige la fila muestra antes de abrir el editor visual.');
      return;
    }

    this.isProcessing = true;

    try {
      if (!row.result?.qr_id) {
        const uploaded = await this.uploadRow(row);

        if (!uploaded) {
          return;
        }
      }

      row.embedStatus = this.hasInjectedPdf(row) ? 'completed' : 'sample';
      this.syncTemplateSource();
      await this.persistCurrentState();
      this.openSampleLocator();
    } finally {
      this.isProcessing = false;
    }
  }

  /**
   * Procesar el resto del lote usando la plantilla de la muestra
   */
  async processBatch(): Promise<void> {
    const sampleRow = this.uploadedSampleRow;

    if (!sampleRow?.result?.qr_id) {
      this.notificationService.showWarning('Primero prepara y guarda el documento muestra.');
      return;
    }

    if (!this.templatePosition) {
      this.notificationService.showWarning('Primero ubica el QR en la muestra y sincroniza la plantilla.');
      return;
    }

    if (this.isProcessing) {
      return;
    }

    const rowsToUpload = this.rows.filter((row) => row.rowId !== sampleRow.rowId && !row.result?.qr_id);
    const readyToUpload = rowsToUpload.filter(row => row.code.trim() && row.file);

    const targetRows = [
      ...readyToUpload,
      ...this.rows.filter(row => row.rowId !== sampleRow.rowId && !!row.result?.qr_id && !this.hasInjectedPdf(row))
    ];

    if (!targetRows.length) {
      if (rowsToUpload.length > 0) {
         this.notificationService.showWarning('No hay documentos listos para inyectar. Asegúrate de haber escrito el Código IN y subido el PDF en las filas que deseas procesar.');
      } else {
         this.notificationService.showInfo('El resto del lote ya fue procesado con la plantilla actual.');
      }
      return;
    }

    this.isProcessing = true;
    this.isBatchFinished = false;
    this.overallProgress = 0;
    let completedCount = 0;
    let failedCount = 0;

    try {
      const totalToProcess = targetRows.length;
      
      for (let idx = 0; idx < targetRows.length; idx++) {
        const row = targetRows[idx];
        
        if (!row.result?.qr_id) {
          const uploaded = await this.uploadRow(row);

          if (!uploaded) {
            failedCount++;
            this.updateOverallProgress(idx + 1, totalToProcess);
            continue;
          }
        }

        if (this.hasInjectedPdf(row)) {
          continue;
        }

        row.embedStatus = 'processing';
        row.embedErrorMessage = null;

        try {
          const response = await firstValueFrom(
            this.docqrService.embedQr(
              row.result!.qr_id,
              {
                x: this.templatePosition.x,
                y: this.templatePosition.y,
                width: this.templatePosition.width,
                height: this.templatePosition.height
              },
              this.templatePosition.page_number ?? 0
            )
          );

          row.embedStatus = 'completed';
          row.embedErrorMessage = null;
          row.finalPdfUrl = response.data.final_pdf_url;
          row.reviewStatus = 'pending';
          completedCount++;
        } catch (error) {
          row.embedStatus = 'error';
          row.embedErrorMessage = this.getApiErrorMessage(error);
          failedCount++;
        }
        
        this.updateOverallProgress(idx + 1, totalToProcess);
      }

      this.syncTemplateSource();
      await this.persistCurrentState();

      if (failedCount === 0) {
        if (this.allRowsInjected) {
          this.notificationService.showSuccess('Todos subidos. Verifica el resultado final en Mis Documentos.');
        } else {
          this.notificationService.showSuccess(`Resto del lote procesado correctamente. Documentos inyectados: ${completedCount}.`);
        }
      } else {
        this.notificationService.showWarning(
          `Procesamiento del lote con incidencias. Inyectados: ${completedCount}. Con error: ${failedCount}.`
        );
      }
      
      // Asegurar que estamos en el Paso 3 para ver los resultados
      this.currentStep = 3;
      this.isBatchFinished = true;
    } finally {
      this.isProcessing = false;
    }
  }

  /**
   * Actualizar el progreso general del lote
   */
  private updateOverallProgress(current: number, total: number): void {
    this.overallProgress = Math.round((current / total) * 100);
  }

  /**
   * Copiar URL del QR de una fila procesada
   */
  async copyQrUrl(row: BulkDraftRow): Promise<void> {
    if (!row.result?.qr_url) {
      this.notificationService.showWarning('La fila aún no tiene URL de QR disponible.');
      return;
    }

    if (!navigator.clipboard) {
      this.notificationService.showWarning('El navegador no permite copiar al portapapeles en este contexto.');
      return;
    }

    try {
      await navigator.clipboard.writeText(row.result.qr_url);
      this.notificationService.showSuccess(`URL del QR copiada para ${row.result.folder_name}.`);
    } catch (error) {
      console.error('No se pudo copiar la URL del QR.', error);
      this.notificationService.showError('No se pudo copiar la URL del QR.');
    }
  }

  /**
   * Abrir el editor como acción opcional para excepciones
   */
  openEditor(row: BulkDraftRow, mode: 'sample' | 'edit' = this.isSampleRow(row) ? 'sample' : 'edit'): void {
    if (!row.result?.qr_id) {
      return;
    }

    this.editorRowId = row.rowId;
    this.editorDocumentName = row.result?.folder_name || row.fileName || 'Editor de QR';
    this.editorModalMode = mode;
    const editorUrl = this.router.serializeUrl(
      this.router.createUrlTree(['/editor', row.result.qr_id], {
        queryParams: {
          embedded: 1,
          context: mode,
          t: Date.now()
        }
      })
    );
    this.editorFrameUrl = this.sanitizer.bypassSecurityTrustResourceUrl(editorUrl);
    this.editorModalOpen = true;
  }

  /**
   * Ir al listado general de documentos
   */
  goToDocuments(): void {
    this.router.navigate(['/documents']);
  }

  /**
   * Obtener etiqueta legible del estado de una fila
   */
  getStatusLabel(status: BulkRowStatus): string {
    switch (status) {
      case 'ready':
        return 'Listo';
      case 'validating':
        return 'Validando';
      case 'processing':
        return 'Procesando';
      case 'success':
        return 'Exitoso';
      case 'error':
        return 'Error';
      case 'draft':
      default:
        return 'Borrador';
    }
  }

  /**
   * Formatear fecha para visualización
   */
  formatEmissionDate(date: string | null): string {
    if (!date) {
      return 'Sin fecha de emisión';
    }

    return new Date(`${date}T00:00:00`).toLocaleDateString('es-PE', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    });
  }

  /**
   * Obtener el tamaño del archivo en MB
   */
  getFileSizeLabel(row: BulkDraftRow): string {
    if (!row.file) {
      return 'Sin archivo';
    }

    return `${(row.file.size / (1024 * 1024)).toFixed(2)} MB`;
  }

  /**
   * Verificar si la fila tiene un PDF válido listo para procesar
   */
  hasReadyPdf(row: BulkDraftRow): boolean {
    return !!row.file && !this.validateSelectedFile(row.file) && !row.pdfDuplicateError;
  }

  /**
   * Verificar si hubo un fallo relacionado con la carga del PDF
   */
  hasPdfFailure(row: BulkDraftRow): boolean {
    if (row.pdfDuplicateError) {
      return true;
    }

    if (row.file && !!this.validateSelectedFile(row.file)) {
      return true;
    }

    if (row.status === 'error' && row.file && this.isPdfErrorMessage(row.errorMessage)) {
      return true;
    }

    if (!row.file && row.status === 'error' && row.errorMessage) {
      return this.isPdfErrorMessage(row.errorMessage);
    }

    return false;
  }

  /**
   * Verificar si el documento ya tiene un PDF final con QR inyectado
   */
  hasInjectedPdf(row: BulkDraftRow): boolean {
    return row.embedStatus === 'completed' || !!row.finalPdfUrl;
  }

  /**
   * Obtener mensaje visible para errores o advertencias del PDF
   */
  getPdfValidationMessage(row: BulkDraftRow): string | null {
    if (row.pdfDuplicateError) {
      return row.pdfDuplicateError;
    }

    if (row.file) {
      return this.validateSelectedFile(row.file);
    }

    return null;
  }

  /**
   * Obtener filas exitosas para la sección de resultados
   */
  get successRows(): BulkDraftRow[] {
    return this.rows.filter((row) => row.status === 'success' && !!row.result);
  }

  /**
   * Filas que ya tienen el PDF final con QR aplicado
   */
  get injectedRows(): BulkDraftRow[] {
    return this.rows.filter((row) => this.hasInjectedPdf(row));
  }

  /**
   * Indicar si el lote completo ya quedó inyectado
   */
  get allRowsInjected(): boolean {
    return this.rows.length > 0 && this.rows.every((row) => this.hasInjectedPdf(row));
  }

  /**
   * Cantidad de filas listas para subir o procesar
   */
  get readyRowsCount(): number {
    return this.rows.filter((row) => row.status === 'ready').length;
  }

  get missingFilesCount(): number {
    return this.rows.filter(row => !row.file && !this.isSampleRow(row)).length;
  }

  get hasMissingFiles(): boolean {
    return this.missingFilesCount > 0;
  }

  /**
   * Filas disponibles para plantilla e inyección
   */
  get uploadedRows(): BulkDraftRow[] {
    return this.rows.filter((row) => !!row.result?.qr_id);
  }

  /**
   * Filas que ya tienen un PDF seleccionado en el lote
   */
  get selectedBatchRows(): BulkDraftRow[] {
    return this.rows.filter((row) => !!row.file);
  }

  /**
   * Cantidad actual de PDFs ya asignados a filas del lote
   */
  get selectedBatchFilesCount(): number {
    return this.selectedBatchRows.length;
  }

  /**
   * Resumen corto de las primeras asignaciones masivas visibles para el usuario
   */
  get batchSelectionPreview(): Array<{ rowNumber: number; fileName: string }> {
    return this.selectedBatchRows.slice(0, 4).map((row) => ({
      rowNumber: this.rows.findIndex((currentRow) => currentRow.rowId === row.rowId) + 1,
      fileName: row.fileName
    }));
  }

  /**
   * Filas que ya fueron revisadas operativamente
   */
  get reviewedRows(): BulkDraftRow[] {
    return this.uploadedRows.filter((row) => row.reviewStatus === 'reviewed' || row.reviewStatus === 'manually_corrected');
  }

  /**
   * Cantidad de documentos aún pendientes de inyección
   */
  get pendingInjectionCount(): number {
    return this.uploadedRows.filter((row) => !this.hasInjectedPdf(row)).length;
  }

  /**
   * Obtener la fila usada como muestra del lote
   */
  get sampleRow(): BulkDraftRow | null {
    if (!this.rows.length) {
      return null;
    }

    return this.rows[0] ?? null;
  }

  /**
   * Obtener la fila muestra ya subida al backend
   */
  get uploadedSampleRow(): BulkDraftRow | null {
    const row = this.sampleRow;
    return row?.result?.qr_id ? row : null;
  }

  /**
   * Verificar si una fila es la muestra actual del lote
   */
  isSampleRow(row: BulkDraftRow): boolean {
    return this.sampleRow?.rowId === row.rowId;
  }

  /**
   * Verificar si hay contenido cargado en pantalla
   */
  hasRowsWithData(): boolean {
    return this.rows.some((row) => !!row.code.trim() || !!row.emissionDate || !!row.file || !!row.result);
  }

  /**
   * TrackBy para filas del ngFor
   */
  trackByRow(_index: number, row: BulkDraftRow): string {
    return row.rowId;
  }

  /**
   * Obtener el mensaje de validación del código
   */
  getCodeValidationMessage(row: BulkDraftRow): string | null {
    if (row.codeError) {
      return row.codeError;
    }

    if (row.codeExists) {
      return `El código ${this.buildFolderName(row.code)} ya existe en el sistema.`;
    }

    return null;
  }

  /**
   * Verificar si el código ya fue validado como disponible
   */
  hasCodeValidationSuccess(row: BulkDraftRow): boolean {
    const normalizedCode = this.normalizeCode(row.code);

    return !!(
      normalizedCode &&
      !row.checkingCode &&
      !row.codeError &&
      !row.codeExists
    );
  }

  /**
   * Seleccionar fila de muestra para la plantilla
   */
  setTemplateSource(rowId: string): void {
    const firstRow = this.rows[0];

    if (!firstRow || firstRow.rowId === rowId) {
      return;
    }

    this.notificationService.showInfo('La muestra del lote siempre es la fila 1.');
  }

  /**
   * Abrir el editor visual del documento muestra
   */
  openSampleLocator(): void {
    const row = this.uploadedSampleRow;

    if (!row) {
      this.notificationService.showWarning('Primero debes preparar el documento muestra para abrir el editor visual.');
      return;
    }

    row.embedStatus = 'sample';
    this.openEditor(row, 'sample');
    this.notificationService.showInfo(
      `Se abrió el posicionador de muestra para ${row.result?.folder_name}. Guarda el QR y la plantilla quedará lista para el resto del lote.`,
      6500
    );
  }

  /**
   * Refrescar plantilla leyendo la posición guardada en el documento muestra
   */
  async refreshTemplateFromSample(silent: boolean = false): Promise<void> {
    const row = this.uploadedSampleRow;

    if (!row?.result?.qr_id) {
      if (!silent) {
        this.notificationService.showWarning('No hay documento muestra disponible.');
      }
      return;
    }

    this.isRefreshingTemplate = true;

    try {
      const response = await firstValueFrom(this.docqrService.getDocumentByQrId(row.result.qr_id));
      const document = response.data;
      const position = document.qr_position;

      if (!position) {
        if (!silent) {
          this.notificationService.showWarning('Aún no hay una posición guardada en el documento muestra.');
        }
        return;
      }

      this.templatePosition = {
        x: Number(position.x),
        y: Number(position.y),
        width: Number(position.width),
        height: Number(position.height),
        page_number: Number(position.page_number ?? 0)
      };
      this.templateSourceDocument = document;
      row.embedStatus = document.final_pdf_url ? 'completed' : 'sample';
      row.embedErrorMessage = null;
      row.finalPdfUrl = document.final_pdf_url || row.finalPdfUrl;
      row.reviewStatus = row.reviewStatus === 'manually_corrected' ? 'manually_corrected' : row.reviewStatus;
      this.awaitingSampleRefresh = false;
      this.rowsPendingEditorSync.delete(row.rowId);

      if (!silent) {
        this.notificationService.showSuccess('Plantilla cargada desde el documento muestra.');
      } else if (document.final_pdf_url) {
        this.notificationService.showSuccess('Documento muestra sincronizado. Ya puedes aplicar la plantilla al lote.');
      }
      await this.persistCurrentState();
    } catch (error) {
      console.error('No se pudo refrescar la plantilla desde el documento muestra.', error);
      if (!silent) {
        this.notificationService.showError('No se pudo leer la posición guardada del documento muestra.');
      }
    } finally {
      this.isRefreshingTemplate = false;
    }
  }

  /**
   * Aplicar la plantilla visual a todo el lote excepto el documento muestra
   */
  async applyTemplateToBatch(): Promise<void> {
    const sampleRow = this.uploadedSampleRow;

    if (!sampleRow?.result?.qr_id) {
      this.notificationService.showWarning('No hay documento muestra para aplicar la plantilla.');
      return;
    }

    if (!this.templatePosition) {
      this.notificationService.showWarning('Primero guarda una posición en el documento muestra y actualiza la plantilla.');
      return;
    }

    const targetRows = this.uploadedRows.filter((row) => row.result?.qr_id && row.rowId !== sampleRow.rowId);

    if (targetRows.length === 0) {
      this.notificationService.showInfo('No hay más documentos en el lote para aplicar la plantilla.');
      return;
    }

    this.isApplyingTemplate = true;
    let completedCount = 0;
    let failedCount = 0;

    for (const row of targetRows) {
      row.embedStatus = 'processing';
      row.embedErrorMessage = null;

      try {
        const response = await firstValueFrom(
          this.docqrService.embedQr(
            row.result!.qr_id,
            {
              x: this.templatePosition.x,
              y: this.templatePosition.y,
              width: this.templatePosition.width,
              height: this.templatePosition.height
            },
            this.templatePosition.page_number ?? 0
          )
        );

        row.embedStatus = 'completed';
        row.embedErrorMessage = null;
        row.finalPdfUrl = response.data.final_pdf_url;
        row.reviewStatus = 'pending';
        completedCount++;
      } catch (error) {
        row.embedStatus = 'error';
        row.embedErrorMessage = this.getApiErrorMessage(error);
        failedCount++;
      }
    }

    this.isApplyingTemplate = false;
    await this.persistCurrentState();

    if (failedCount === 0) {
      this.notificationService.showSuccess(`Plantilla aplicada al lote. Documentos completados: ${completedCount}.`);
    } else {
      this.notificationService.showWarning(
        `Plantilla aplicada con incidencias. Completados: ${completedCount}. Con error: ${failedCount}.`
      );
    }
  }

  /**
   * Abrir vista previa del PDF final de una fila
   */
  async openPreview(row: BulkDraftRow): Promise<void> {
    if (!row.result?.qr_id) {
      this.notificationService.showWarning('La fila aún no tiene un documento generado.');
      return;
    }

    try {
      const response = await firstValueFrom(this.docqrService.getDocumentByQrId(row.result.qr_id));
      const document = response.data;
      const pdfUrl = document.final_pdf_url;

      if (!pdfUrl) {
        this.notificationService.showWarning(
          row.rowId === this.sampleRow?.rowId
            ? 'La muestra aún no tiene el QR inyectado. Abre el ubicador visual, guarda en el editor y vuelve a sincronizar.'
            : 'Este documento todavía no tiene un PDF final con QR. Aplica la plantilla o corrígelo manualmente.'
        );
        return;
      }

      const separator = pdfUrl.includes('?') ? '&' : '?';
      this.previewPdfUrl = `${pdfUrl}${separator}t=${Date.now()}`;
      this.previewDocumentName = document.original_filename || document.folder_name;
      this.previewModalOpen = true;
      row.finalPdfUrl = document.final_pdf_url || row.finalPdfUrl;
      row.embedStatus = 'completed';
      row.embedErrorMessage = null;
      if (this.rowsPendingEditorSync.has(row.rowId)) {
        row.reviewStatus = 'manually_corrected';
        this.rowsPendingEditorSync.delete(row.rowId);
        await this.persistCurrentState();
      }
    } catch (error) {
      console.error('No se pudo abrir la vista previa del documento.', error);
      this.notificationService.showError('No se pudo cargar la vista previa del documento.');
    }
  }

  /**
   * Abrir vista previa del documento muestra
   */
  async openSamplePreview(): Promise<void> {
    const row = this.uploadedSampleRow;

    if (!row) {
      this.notificationService.showWarning('No hay documento muestra disponible.');
      return;
    }

    await this.openPreview(row);
  }

  /**
   * Cerrar el editor visual embebido
   */
  closeEditorModal(): void {
    this.editorModalOpen = false;
    this.editorFrameUrl = null;
    this.editorDocumentName = '';
    this.editorRowId = null;
    this.editorModalMode = 'sample';
  }

  /**
   * Sincronizar filas después de editar manualmente en el editor visual
   */
  private async syncRowsAfterManualEdit(): Promise<void> {
    const rowsToSync = this.uploadedRows.filter((row) => this.rowsPendingEditorSync.has(row.rowId) && !!row.result?.qr_id);

    if (!rowsToSync.length) {
      return;
    }

    let changed = false;
    let correctedCount = 0;

    for (const row of rowsToSync) {
      try {
        const response = await firstValueFrom(this.docqrService.getDocumentByQrId(row.result!.qr_id));
        const document = response.data;
        const hadInjectedPdf = this.hasInjectedPdf(row);

        row.finalPdfUrl = document.final_pdf_url || row.finalPdfUrl;
        row.embedErrorMessage = null;

        if (document.final_pdf_url) {
          row.embedStatus = 'completed';
          row.reviewStatus = 'manually_corrected';
          this.rowsPendingEditorSync.delete(row.rowId);
          changed = true;

          if (!hadInjectedPdf) {
            correctedCount++;
          }
        }

        if (row.rowId === this.sampleRow?.rowId && document.qr_position) {
          this.templatePosition = {
            x: Number(document.qr_position.x),
            y: Number(document.qr_position.y),
            width: Number(document.qr_position.width),
            height: Number(document.qr_position.height),
            page_number: Number(document.qr_position.page_number ?? 1)
          };
          this.templateSourceDocument = document;
          changed = true;
        }
      } catch (error) {
        console.error('No se pudo sincronizar la fila editada manualmente.', error);
      }
    }

    if (changed) {
      await this.persistCurrentState();
    }

    if (correctedCount > 0) {
      this.notificationService.showSuccess(`Se sincronizaron ${correctedCount} documento(s) corregidos manualmente.`);
    }
  }

  /**
   * Cerrar modal de vista previa
   */
  closePreviewModal(): void {
    this.previewModalOpen = false;
    this.previewPdfUrl = '';
    this.previewDocumentName = '';
  }

  /**
   * Obtener etiqueta legible del estado de inyección
   */
  getEmbedStatusLabel(status: BulkEmbedStatus): string {
    switch (status) {
      case 'sample':
        return 'Muestra';
      case 'processing':
        return 'Aplicando';
      case 'completed':
        return 'Inyectado';
      case 'error':
        return 'Error';
      case 'pending':
      default:
        return 'Pendiente';
    }
  }

  /**
   * Obtener etiqueta legible del control operativo
   */
  getReviewStatusLabel(status: BulkReviewStatus): string {
    switch (status) {
      case 'reviewed':
        return 'Revisado';
      case 'manually_corrected':
        return 'Corregido manualmente';
      case 'pending':
      default:
        return 'Pendiente de revisión';
    }
  }

  /**
   * Marcar fila como revisada operativamente
   */
  async markRowAsReviewed(row: BulkDraftRow): Promise<void> {
    if (!this.hasInjectedPdf(row)) {
      this.notificationService.showWarning('Primero debes inyectar el QR para marcar esta fila como revisada.');
      return;
    }

    row.reviewStatus = 'reviewed';
    await this.persistCurrentState();
    this.notificationService.showSuccess(`Fila ${row.result?.folder_name} marcada como revisada.`);
  }

  /**
   * Marcar todas las filas inyectadas como revisadas
   */
  async markBatchAsReviewed(): Promise<void> {
    const injectableRows = this.uploadedRows.filter((row) => this.hasInjectedPdf(row));

    if (!injectableRows.length) {
      this.notificationService.showWarning('Todavía no hay documentos con QR inyectado para revisar.');
      return;
    }

    injectableRows.forEach((row) => {
      if (row.reviewStatus !== 'manually_corrected') {
        row.reviewStatus = 'reviewed';
      }
    });

    await this.persistCurrentState();
    this.notificationService.showSuccess('Se marcó el lote inyectado como revisado.');
  }

  /**
   * Toggle del sidebar
   */
  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  /**
   * Cerrar sidebar
   */
  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }

  /**
   * Crear una fila vacía del lote
   */
  private createEmptyRow(): BulkDraftRow {
    return {
      rowId: this.generateRowId(),
      code: '',
      checkingCode: false,
      codeExists: false,
      codeError: null,
      emissionDate: '',
      fileName: '',
      fileType: '',
      fileLastModified: 0,
      file: null,
      pdfDuplicateError: null,
      status: 'draft',
      errorMessage: null,
      embedStatus: 'pending',
      embedErrorMessage: null,
      finalPdfUrl: null,
      reviewStatus: 'pending',
      result: null
    };
  }

  /**
   * Construir el estado serializable del borrador
   */
  private buildDraftState(rows: BulkDraftRow[]): BulkDraftState {
    return {
      generatedCount: rows.length,
      rows: rows.map((row) => this.cloneRow(row)),
      savedAt: new Date().toISOString()
    };
  }

  /**
   * Clonar una fila para no compartir referencias mutables
   */
  private cloneRow(row: BulkDraftRow): BulkDraftRow {
    return {
      ...row,
      checkingCode: false,
      codeExists: false,
      codeError: null,
      embedStatus: row.embedStatus ?? 'pending',
      embedErrorMessage: row.embedErrorMessage ?? null,
      finalPdfUrl: row.finalPdfUrl ?? null,
      pdfDuplicateError: row.pdfDuplicateError ?? null,
      reviewStatus: row.reviewStatus ?? 'pending',
      file: row.file ?? null,
      result: row.result ? { ...row.result } : null
    };
  }

  /**
   * Marcar fila como editable nuevamente si se modifica tras un éxito previo
   */
  private markRowAsDirty(row: BulkDraftRow): void {
    if (row.status === 'success') {
      row.result = null;
    }

    row.embedStatus = 'pending';
    row.embedErrorMessage = null;
    row.finalPdfUrl = null;
    row.reviewStatus = 'pending';

    if (row.status !== 'processing') {
      row.errorMessage = null;
    }
  }

  /**
   * Mantener el estado visual de la fila según los datos cargados
   */
  private syncRowStatus(row: BulkDraftRow): void {
    if (row.status === 'processing' || row.status === 'validating') {
      return;
    }

    if (row.status === 'success' && row.result) {
      return;
    }

    if (row.checkingCode || row.codeExists || row.codeError || row.pdfDuplicateError) {
      row.status = 'draft';
      return;
    }

    const validationMessage = this.getRowValidationMessage(row, null, false);

    if (validationMessage) {
      row.status = 'draft';
      return;
    }

    row.status = 'ready';
  }

  /**
   * Subir una fila individual al backend
   */
  private async uploadRow(row: BulkDraftRow): Promise<boolean> {
    if (row.result?.qr_id) {
      return true;
    }

    row.status = 'validating';
    row.errorMessage = null;

    const folderName = this.buildFolderName(row.code);

    try {
      const codeValidation = await firstValueFrom(this.docqrService.checkCodeExists(folderName));

      if (codeValidation.exists) {
        row.checkingCode = false;
        row.codeExists = true;
        row.codeError = null;
        row.status = 'error';
        row.errorMessage = `El código ${folderName} ya existe en el sistema.`;
        return false;
      }

      row.status = 'processing';
      row.codeExists = false;
      row.codeError = null;

      const response = await firstValueFrom(
        this.docqrService.uploadPdf(row.file as File, folderName, row.emissionDate)
      );

      row.status = 'success';
      row.checkingCode = false;
      row.codeExists = false;
      row.codeError = null;
      row.errorMessage = null;
      row.embedStatus = 'pending';
      row.embedErrorMessage = null;
      row.finalPdfUrl = null;
      row.reviewStatus = 'pending';
      row.result = response.data as BulkRowProcessResult;
      row.fileName = response.data.original_filename;
      return true;
    } catch (error) {
      row.checkingCode = false;
      row.codeExists = false;
      row.status = 'error';
      row.errorMessage = this.getApiErrorMessage(error);
      return false;
    }
  }

  /**
   * Validar filas antes de iniciar el procesamiento
   */
  private validateRowsBeforeProcessing(rows: BulkDraftRow[]): boolean {
    const duplicates = this.getDuplicateCodes(rows);
    this.refreshPdfDuplicateValidation(rows);
    let isValid = true;

    rows.forEach((row) => {
      const validationMessage = this.getRowValidationMessage(row, duplicates, true, true);

      if (validationMessage) {
        row.status = 'error';
        row.errorMessage = validationMessage;
        isValid = false;
      } else {
        row.errorMessage = null;
        row.status = 'ready';
      }
    });

    return isValid;
  }

  /**
   * Obtener validación de una fila
   */
  private getRowValidationMessage(
    row: BulkDraftRow,
    duplicateCodes: Set<string> | null,
    includeDuplicates: boolean,
    ignoreRemoteCodeState: boolean = false
  ): string | null {
    const normalizedCode = this.normalizeCode(row.code);

    if (!normalizedCode) {
      return 'El código es obligatorio.';
    }

    if (!/^[A-Z0-9-]+$/.test(normalizedCode)) {
      return 'El código solo puede contener letras, números y guiones.';
    }

    if (includeDuplicates && duplicateCodes?.has(normalizedCode)) {
      return `El código IN-${normalizedCode} está duplicado dentro del lote.`;
    }

    if (row.codeExists) {
      return `El código ${this.buildFolderName(row.code)} ya existe en el sistema.`;
    }

    if (!ignoreRemoteCodeState && row.codeError) {
      return row.codeError;
    }

    if (!ignoreRemoteCodeState && row.checkingCode) {
      return 'Espera a que termine la validación del código.';
    }

    if (!row.emissionDate) {
      return 'La fecha de emisión es obligatoria.';
    }

    if (row.emissionDate > this.maxEmissionDate) {
      return 'La fecha de emisión no puede ser futura.';
    }

    if (!row.file) {
      return 'Debes adjuntar un archivo PDF.';
    }

    if (row.pdfDuplicateError) {
      return row.pdfDuplicateError;
    }

    return this.validateSelectedFile(row.file);
  }

  /**
   * Detectar códigos duplicados dentro del lote
   */
  private getDuplicateCodes(rows: BulkDraftRow[]): Set<string> {
    const counts = new Map<string, number>();

    rows.forEach((row) => {
      const normalizedCode = this.normalizeCode(row.code);
      if (!normalizedCode) {
        return;
      }

      counts.set(normalizedCode, (counts.get(normalizedCode) ?? 0) + 1);
    });

    return new Set(
      Array.from(counts.entries())
        .filter(([, count]) => count > 1)
        .map(([code]) => code)
    );
  }

  /**
   * Refrescar validación de PDFs duplicados dentro del lote actual
   */
  private refreshPdfDuplicateValidation(rowsToSyncStatus: BulkDraftRow[]): void {
    const fileGroups = new Map<string, BulkDraftRow[]>();

    this.rows.forEach((row) => {
      row.pdfDuplicateError = null;

      const signature = this.buildPdfSignature(row);
      if (!signature) {
        return;
      }

      const existingRows = fileGroups.get(signature) ?? [];
      existingRows.push(row);
      fileGroups.set(signature, existingRows);
    });

    fileGroups.forEach((groupRows) => {
      if (groupRows.length < 2) {
        return;
      }

      groupRows.forEach((row) => {
        const duplicateRows = groupRows
          .filter((groupRow) => groupRow.rowId !== row.rowId)
          .map((groupRow) => this.getRowNumber(groupRow))
          .sort((left, right) => left - right);

        row.pdfDuplicateError = `Este PDF está duplicado con la fila ${duplicateRows.join(', ')} del lote.`;
      });
    });

    rowsToSyncStatus.forEach((row) => this.syncRowStatus(row));
  }

  /**
   * Validar archivo PDF seleccionado
   */
  private validateSelectedFile(file: File): string | null {
    const isValidPdf = file.type === 'application/pdf' ||
      file.type === 'application/x-pdf' ||
      file.type === 'application/octet-stream' ||
      file.name.toLowerCase().endsWith('.pdf');

    if (!isValidPdf) {
      return 'Solo se permiten archivos PDF.';
    }

    if (file.size === 0) {
      return 'El archivo está vacío.';
    }

    if (file.size > this.maxFileSize) {
      return `El archivo debe ser menor a ${(this.maxFileSize / (1024 * 1024)).toFixed(0)} MB.`;
    }

    return null;
  }

  /**
   * Construir el código completo con prefijo IN
   */
  private buildFolderName(code: string): string {
    return `IN-${this.normalizeCode(code)}`;
  }

  /**
   * Normalizar el código ingresado por el usuario
   */
  private normalizeCode(code: string): string {
    return code.trim().toUpperCase();
  }

  /**
   * Obtener firma ligera del PDF para detectar duplicados evidentes
   */
  private buildPdfSignature(row: BulkDraftRow): string | null {
    if (!row.file) {
      return null;
    }

    return [
      row.file.name.trim().toLowerCase(),
      row.file.size,
      row.file.lastModified
    ].join('::');
  }

  /**
   * Obtener el número visible de fila
   */
  private getRowNumber(row: BulkDraftRow): number {
    return this.rows.findIndex((currentRow) => currentRow.rowId === row.rowId) + 1;
  }

  /**
   * Mantener seleccionada una fila de muestra válida y refrescar plantilla si es necesario
   */
  private syncTemplateSource(): void {
    if (!this.rows.length) {
      this.templateSourceRowId = null;
      this.templatePosition = null;
      this.templateSourceDocument = null;
      this.awaitingSampleRefresh = false;
      this.rowsPendingEditorSync.clear();
      return;
    }

    this.templateSourceRowId = this.rows[0].rowId;

    // Sincronizar estados de inyección básicos
    this.uploadedRows.forEach((row) => {
      if (row.rowId === this.templateSourceRowId && (row.embedStatus === 'pending' || !row.embedStatus)) {
        row.embedStatus = 'sample';
      } else if (row.rowId !== this.templateSourceRowId && row.embedStatus === 'sample') {
        row.embedStatus = row.finalPdfUrl ? 'completed' : 'pending';
      }
    });

    // Si estamos en el paso de plantilla y no tenemos la posición, intentar refrescarla automáticamente
    if ((this.currentStep === 2 || this.currentStep === 3) && !this.templatePosition && !this.isRefreshingTemplate) {
      const sample = this.uploadedSampleRow;
      if (sample?.result?.qr_id) {
        void this.refreshTemplateFromSample(true);
      }
    }
  }

  /**
   * Persistir el estado actual del lote
   */
  private async persistCurrentState(): Promise<void> {
    if (!this.rows.length) {
      await this.draftService.clearDraft();
      this.storedDraft = null;
      return;
    }

    const state = this.buildDraftState(this.rows);
    await this.draftService.saveDraft(state);
    this.storedDraft = state;
  }

  /**
   * Refrescar validación visual del código para las filas indicadas
   */
  private refreshCodeValidation(rowsToCheck: BulkDraftRow[]): void {
    const duplicates = this.getDuplicateCodes(this.rows);
    const rowsToCheckIds = new Set(rowsToCheck.map((row) => row.rowId));

    this.rows.forEach((row) => {
      const normalizedCode = this.normalizeCode(row.code);
      const hadDuplicateError = row.codeError?.includes('duplicado dentro del lote') ?? false;

      if (!normalizedCode) {
        row.codeError = null;
        row.codeExists = false;
        row.checkingCode = false;
        this.clearCodeCheckTimeout(row.rowId);
        this.syncRowStatus(row);
        return;
      }

      if (!/^[A-Z0-9-]+$/.test(normalizedCode)) {
        row.codeError = 'El código solo puede contener letras, números y guiones.';
        row.codeExists = false;
        row.checkingCode = false;
        this.clearCodeCheckTimeout(row.rowId);
        this.syncRowStatus(row);
        return;
      }

      if (duplicates.has(normalizedCode)) {
        row.codeError = `El código IN-${normalizedCode} está duplicado dentro del lote.`;
        row.codeExists = false;
        row.checkingCode = false;
        this.clearCodeCheckTimeout(row.rowId);
        this.syncRowStatus(row);
        return;
      }

      row.codeError = null;

      if (rowsToCheckIds.has(row.rowId) || hadDuplicateError) {
        this.scheduleCodeAvailabilityCheck(row);
        return;
      }

      this.syncRowStatus(row);
    });
  }

  /**
   * Programar verificación del código en backend con debounce
   */
  private scheduleCodeAvailabilityCheck(row: BulkDraftRow): void {
    this.clearCodeCheckTimeout(row.rowId);
    row.checkingCode = true;
    row.codeExists = false;
    row.codeError = null;
    this.syncRowStatus(row);

    const timeoutId = setTimeout(() => {
      this.codeCheckTimeouts.delete(row.rowId);
      const folderName = this.buildFolderName(row.code);

      this.docqrService.checkCodeExists(folderName).subscribe({
        next: (response) => {
          row.checkingCode = false;
          row.codeExists = response.exists;
          row.codeError = null;
          this.syncRowStatus(row);
        },
        error: () => {
          row.checkingCode = false;
          row.codeExists = false;
          row.codeError = 'No se pudo validar el código en este momento.';
          this.syncRowStatus(row);
        }
      });
    }, 500);

    this.codeCheckTimeouts.set(row.rowId, timeoutId);
  }

  /**
   * Limpiar un debounce pendiente de validación de código
   */
  private clearCodeCheckTimeout(rowId: string): void {
    const timeoutId = this.codeCheckTimeouts.get(rowId);

    if (timeoutId) {
      clearTimeout(timeoutId);
      this.codeCheckTimeouts.delete(rowId);
    }
  }

  /**
   * Limpiar todos los debounce pendientes
   */
  private clearAllCodeCheckTimeouts(): void {
    this.codeCheckTimeouts.forEach((timeoutId) => clearTimeout(timeoutId));
    this.codeCheckTimeouts.clear();
  }

  /**
   * Resolver mensaje legible de error API
   */
  private getApiErrorMessage(error: any): string {
    if (error?.error?.errors) {
      const firstError = Object.values(error.error.errors)[0];
      if (Array.isArray(firstError) && firstError.length > 0) {
        return firstError[0] as string;
      }
    }

    if (error?.error?.message) {
      return error.error.message;
    }

    if (error?.message) {
      return error.message;
    }

    if (error?.status === 0) {
      return 'No se pudo conectar con el servidor.';
    }

    return 'Ocurrió un error al procesar esta fila.';
  }

  /**
   * Generar identificador único para filas del lote
   */
  private generateRowId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }

    return `bulk-row-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }

  /**
   * Identificar mensajes realmente asociados al PDF
   */
  private isPdfErrorMessage(message: string | null | undefined): boolean {
    if (!message) {
      return false;
    }

    const normalizedMessage = message.toLowerCase();
    return normalizedMessage.includes('pdf') || normalizedMessage.includes('archivo');
  }

  /**
   * Aplicar un archivo PDF a una fila y dejarla lista para validación
   */
  private applyFileToRow(row: BulkDraftRow, file: File): string | null {
    const validationMessage = this.validateSelectedFile(file);

    if (validationMessage) {
      row.status = 'error';
      row.errorMessage = validationMessage;
      return validationMessage;
    }

    this.markRowAsDirty(row);
    row.file = file;
    row.fileName = file.name;
    row.fileType = file.type || 'application/pdf';
    row.fileLastModified = file.lastModified;
    row.errorMessage = null;
    row.pdfDuplicateError = null;
    this.syncRowStatus(row);
    return null;
  }
}
