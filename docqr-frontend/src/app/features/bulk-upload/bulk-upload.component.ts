import { Component, ElementRef, OnInit, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { DocqrService } from '../../core/services/docqr.service';
import { NotificationService } from '../../core/services/notification.service';
import { HeaderComponent } from '../../shared/components/header/header.component';
import { SidebarComponent } from '../../shared/components/sidebar/sidebar.component';

interface DraftDocument {
  id: string;
  sort_index: number;
  server_id?: number;
  folder_name: string;
  fecha_emision: string;
  qr_id?: string;
  qr_url?: string;
  view_url?: string | null;
  qr_image_url?: string;
  has_server_pdf?: boolean;
  status?: string;
  render_mode?: string;
  pdf_url?: string | null;
  final_pdf_url?: string | null;
  file?: File;
  original_filename?: string;
  file_size?: number;
  created_at: string;
}

interface BulkEntry {
  id: string;
  file?: File;
  label: string;
  code: string;
  error: string;
}

interface ConfirmationModalState {
  open: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  confirmTone: 'danger' | 'primary';
}

@Component({
  selector: 'app-bulk-upload',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, HeaderComponent, SidebarComponent],
  templateUrl: './bulk-upload.component.html',
  styleUrls: ['./bulk-upload.component.scss']
})
export class BulkUploadComponent implements OnInit {
  @ViewChild('bulkFileInput') bulkFileInput!: ElementRef<HTMLInputElement>;
  @ViewChild('injectFileInput') injectFileInput!: ElementRef<HTMLInputElement>;

  sidebarOpen = false;
  loading = true;
  searchTerm = '';

  private readonly STORAGE_KEY = 'bulk_upload_drafts';
  private readonly FILE_DB_NAME = 'docqr_bulk_upload';
  private readonly FILE_STORE_NAME = 'draft_files';
  private draftFilesDbPromise: Promise<IDBDatabase> | null = null;

  documents: DraftDocument[] = [];
  filteredDocuments: DraftDocument[] = [];

  showCreateForm = false;
  newCode = '';
  newFechaEmision = '';
  creatingRow = false;
  codeError = '';

  bulkUploading = false;
  bulkFechaEmision = '';
  showBulkModal = false;
  bulkEntries: BulkEntry[] = [];

  showInjectModal = false;
  injectingDoc: DraftDocument | null = null;
  injectFile: File | null = null;
  injectCode = '';
  injectCodeError = '';
  injectFechaEmision = '';
  injectPreparing = false;
  injectSaving = false;
  injectDragOver = false;

  copyingLinkDraftId: string | null = null;
  copyingQrDraftId: string | null = null;
  removingDraftId: string | null = null;

  savingAll = false;
  confirmationModal: ConfirmationModalState = {
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    confirmTone: 'primary'
  };
  private pendingConfirmationAction: (() => void | Promise<void>) | null = null;

  constructor(
    private docqrService: DocqrService,
    private notificationService: NotificationService
  ) {}

  ngOnInit(): void {
    if (window.innerWidth >= 768) {
      this.sidebarOpen = true;
    }

    void this.initializeDrafts();
  }

  private async initializeDrafts(): Promise<void> {
    this.loading = true;
    await this.loadDraftsFromStorage();
    this.applySearch();
    this.loading = false;
  }

  private async loadDraftsFromStorage(): Promise<void> {
    try {
      const stored = localStorage.getItem(this.STORAGE_KEY);
      if (!stored) {
        this.documents = [];
        return;
      }

      const parsed = JSON.parse(stored);
      this.documents = parsed.map((doc: any, index: number) => ({
        ...doc,
        sort_index: typeof doc.sort_index === 'number' ? doc.sort_index : index + 1,
        file: undefined
      }));

      await Promise.all(
        this.documents.map(async (doc) => {
          doc.file = await this.getDraftFile(doc.id);
        })
      );
    } catch (error) {
      console.error('Error loading drafts:', error);
      this.documents = [];
    }
  }

  private saveDraftsToStorage(): void {
    try {
      const toStore = this.documents.map(doc => ({
        id: doc.id,
        sort_index: doc.sort_index,
        server_id: doc.server_id,
        folder_name: doc.folder_name,
        fecha_emision: doc.fecha_emision,
        qr_id: doc.qr_id,
        qr_url: doc.qr_url,
        view_url: doc.view_url,
        qr_image_url: doc.qr_image_url,
        has_server_pdf: doc.has_server_pdf,
        status: doc.status,
        render_mode: doc.render_mode,
        pdf_url: doc.pdf_url,
        final_pdf_url: doc.final_pdf_url,
        original_filename: doc.original_filename,
        file_size: doc.file_size,
        created_at: doc.created_at
      }));
      localStorage.setItem(this.STORAGE_KEY, JSON.stringify(toStore));
    } catch (error) {
      console.error('Error saving drafts:', error);
    }
  }

  private async clearDraftsFromStorage(): Promise<void> {
    localStorage.removeItem(this.STORAGE_KEY);
    await this.clearDraftFiles();
  }

  private async openDraftFilesDb(): Promise<IDBDatabase> {
    if (this.draftFilesDbPromise) {
      return this.draftFilesDbPromise;
    }

    this.draftFilesDbPromise = new Promise((resolve, reject) => {
      if (typeof window === 'undefined' || !window.indexedDB) {
        reject(new Error('IndexedDB is not available'));
        return;
      }

      const request = window.indexedDB.open(this.FILE_DB_NAME, 1);

      request.onupgradeneeded = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains(this.FILE_STORE_NAME)) {
          db.createObjectStore(this.FILE_STORE_NAME, { keyPath: 'id' });
        }
      };

      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('Unable to open IndexedDB'));
    });

    return this.draftFilesDbPromise;
  }

  private async runDraftFileTransaction<T>(
    mode: IDBTransactionMode,
    action: (store: IDBObjectStore, resolve: (value: T) => void, reject: (reason?: unknown) => void) => void
  ): Promise<T> {
    const db = await this.openDraftFilesDb();

    return new Promise<T>((resolve, reject) => {
      const transaction = db.transaction(this.FILE_STORE_NAME, mode);
      const store = transaction.objectStore(this.FILE_STORE_NAME);

      action(store, resolve, reject);

      transaction.onerror = () => reject(transaction.error ?? new Error('IndexedDB transaction failed'));
    });
  }

  private async saveDraftFile(draftId: string, file: File): Promise<void> {
    await this.runDraftFileTransaction<void>('readwrite', (store, resolve, reject) => {
      const request = store.put({ id: draftId, file });
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }

  private async getDraftFile(draftId: string): Promise<File | undefined> {
    try {
      return await this.runDraftFileTransaction<File | undefined>('readonly', (store, resolve, reject) => {
        const request = store.get(draftId);
        request.onsuccess = () => resolve(request.result?.file);
        request.onerror = () => reject(request.error);
      });
    } catch (error) {
      console.error('Error restoring draft file:', error);
      return undefined;
    }
  }

  private async deleteDraftFile(draftId: string): Promise<void> {
    try {
      await this.runDraftFileTransaction<void>('readwrite', (store, resolve, reject) => {
        const request = store.delete(draftId);
        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
      });
    } catch (error) {
      console.error('Error deleting draft file:', error);
    }
  }

  private async clearDraftFiles(): Promise<void> {
    try {
      await this.runDraftFileTransaction<void>('readwrite', (store, resolve, reject) => {
        const request = store.clear();
        request.onsuccess = () => resolve();
        request.onerror = () => reject(request.error);
      });
    } catch (error) {
      console.error('Error clearing draft files:', error);
    }
  }

  onSearch(): void {
    this.applySearch();
  }

  private applySearch(): void {
    if (!this.searchTerm.trim()) {
      this.filteredDocuments = this.getSortedDrafts(this.documents);
      return;
    }

    const term = this.searchTerm.toLowerCase();
    this.filteredDocuments = this.getSortedDrafts(
      this.documents.filter(doc =>
        doc.folder_name.toLowerCase().includes(term) ||
        (doc.original_filename && doc.original_filename.toLowerCase().includes(term))
      )
    );
  }

  private getSortedDrafts(drafts: DraftDocument[]): DraftDocument[] {
    return [...drafts].sort((left, right) => left.sort_index - right.sort_index);
  }

  private getNextSortIndex(): number {
    return this.documents.reduce((max, doc) => Math.max(max, doc.sort_index || 0), 0) + 1;
  }

  toggleCreateForm(): void {
    this.showCreateForm = !this.showCreateForm;

    if (this.showCreateForm) {
      this.newCode = '';
      this.newFechaEmision = new Date().toISOString().split('T')[0];
      this.codeError = '';
    }
  }

  async validateCode(): Promise<void> {
    this.newCode = this.normalizeCode(this.newCode);
    this.codeError = this.getDraftCodeError(this.newCode);

    if (this.codeError) {
      return;
    }

    if (await this.codeExistsInSystem(this.newCode)) {
      this.codeError = 'Este codigo ya existe en el sistema';
    }
  }

  async createRow(): Promise<void> {
    this.newCode = this.normalizeCode(this.newCode);
    this.codeError = this.getDraftCodeError(this.newCode);

    if (this.codeError) {
      return;
    }

    if (!this.newFechaEmision) {
      this.notificationService.showError('La fecha de emision es obligatoria');
      return;
    }

    if (await this.codeExistsInSystem(this.newCode)) {
      this.codeError = 'Este codigo ya existe en el sistema';
      return;
    }

    this.creatingRow = true;

    try {
      const draft: DraftDocument = {
        id: this.generateId(),
        sort_index: this.getNextSortIndex(),
        folder_name: this.toFolderName(this.newCode),
        fecha_emision: this.newFechaEmision,
        created_at: new Date().toISOString()
      };

      this.documents.push(draft);
      this.saveDraftsToStorage();
      this.applySearch();
      this.notificationService.showSuccess('Borrador creado');
      this.showCreateForm = false;
      this.newCode = '';
      this.newFechaEmision = '';
    } finally {
      this.creatingRow = false;
    }
  }

  private generateId(): string {
    return `draft_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`;
  }

  private createBulkEntry(file?: File): BulkEntry {
    return {
      id: this.generateId(),
      file,
      label: file?.name || 'Sin PDF aun',
      code: '',
      error: ''
    };
  }

  openBulkModal(): void {
    this.showBulkModal = true;
    this.bulkUploading = false;
    this.bulkEntries = [this.createBulkEntry()];
    this.bulkFechaEmision = new Date().toISOString().split('T')[0];
  }

  closeBulkModal(): void {
    this.showBulkModal = false;
    this.bulkUploading = false;
    this.bulkEntries = [];
    this.bulkFechaEmision = '';
  }

  addBulkCodeRow(): void {
    this.bulkEntries.push(this.createBulkEntry());
  }

  resetBulkEntries(): void {
    this.bulkEntries = [this.createBulkEntry()];
    this.bulkUploading = false;
  }

  onBulkFilesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (input.files) {
      const files = Array.from(input.files).filter(file => file.name.toLowerCase().endsWith('.pdf'));

      if (files.length !== input.files.length) {
        this.notificationService.showWarning('Se ignoraron archivos que no son PDF');
      }

      files.forEach(file => {
        const duplicate = this.bulkEntries.find(entry =>
          entry.file &&
          entry.file.name === file.name &&
          entry.file.size === file.size
        );

        if (!duplicate) {
          this.bulkEntries.push(this.createBulkEntry(file));
        }
      });
    }

    if (this.bulkFileInput) {
      this.bulkFileInput.nativeElement.value = '';
    }
  }

  removeBulkEntry(index: number): void {
    this.bulkEntries.splice(index, 1);

    if (this.bulkEntries.length === 0) {
      this.bulkEntries = [this.createBulkEntry()];
    }
  }

  validateBulkCodeLocal(entry: BulkEntry): void {
    const code = this.normalizeCode(entry.code);
    entry.code = code;
    entry.error = this.getBulkCodeError(entry.id, code);
  }

  async validateBulkCode(entry: BulkEntry): Promise<void> {
    const code = this.normalizeCode(entry.code);
    entry.code = code;
    entry.error = this.getBulkCodeError(entry.id, code);

    if (entry.error) {
      return;
    }

    if (await this.codeExistsInSystem(code) && this.normalizeCode(entry.code) === code) {
      entry.error = 'Ya existe en el sistema';
    }
  }

  canExecuteBulkUpload(): boolean {
    if (this.bulkEntries.length === 0) return false;
    if (!this.bulkFechaEmision) return false;

    for (const entry of this.bulkEntries) {
      const code = this.normalizeCode(entry.code);
      if (!code || !!this.getCodeFormatError(code)) {
        return false;
      }
      if (entry.error) {
        return false;
      }
    }

    return true;
  }

  async executeBulkUpload(): Promise<void> {
    this.bulkUploading = true;

    try {
      for (const entry of this.bulkEntries) {
        await this.validateBulkCode(entry);
      }

      if (!this.canExecuteBulkUpload()) {
        this.notificationService.showError('Completa todos los codigos correctamente');
        return;
      }

      let nextSortIndex = this.getNextSortIndex();

      for (const entry of this.bulkEntries) {
        const draft: DraftDocument = {
          id: this.generateId(),
          sort_index: nextSortIndex++,
          folder_name: this.toFolderName(entry.code),
          fecha_emision: this.bulkFechaEmision,
          file: entry.file,
          original_filename: entry.file?.name,
          file_size: entry.file?.size,
          created_at: new Date().toISOString()
        };

        this.documents.push(draft);
        if (entry.file) {
          await this.saveDraftFile(draft.id, entry.file);
        }
      }

      this.saveDraftsToStorage();
      this.applySearch();
      this.notificationService.showSuccess(`${this.bulkEntries.length} borrador(es) agregado(s)`);
      this.closeBulkModal();
    } catch (error) {
      console.error('Error creating local bulk drafts:', error);
      this.notificationService.showError(this.getErrorMessage(error, 'No se pudieron agregar los borradores'));
    } finally {
      this.bulkUploading = false;
    }
  }

  async openInjectModal(doc: DraftDocument): Promise<void> {
    this.injectingDoc = doc;
    this.injectFile = null;
    this.injectCode = this.toCodeInput(doc.folder_name);
    this.injectCodeError = '';
    this.injectFechaEmision = doc.fecha_emision || new Date().toISOString().split('T')[0];
    this.injectPreparing = true;
    this.injectSaving = false;
    this.injectDragOver = false;
    this.showInjectModal = true;

    if (this.injectFileInput) {
      this.injectFileInput.nativeElement.value = '';
    }

    try {
      await this.prepareDraftForManualQrFlow(doc);
    } catch (error: any) {
      console.error('Error preparing draft for QR workflow:', error);
      this.notificationService.showError(
        this.getErrorMessage(error, 'No se pudo preparar el QR para este borrador')
      );
    } finally {
      this.injectPreparing = false;
    }
  }

  closeInjectModal(): void {
    this.showInjectModal = false;
    this.injectingDoc = null;
    this.injectFile = null;
    this.injectCode = '';
    this.injectCodeError = '';
    this.injectFechaEmision = '';
    this.injectPreparing = false;
    this.injectSaving = false;
    this.injectDragOver = false;
  }

  onInjectFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;

    if (!input.files || input.files.length === 0) {
      return;
    }

    this.setInjectFile(input.files[0]);
  }

  onInjectDragOver(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();

    if (this.injectSaving || this.injectPreparing) {
      return;
    }

    this.injectDragOver = true;
  }

  onInjectDragLeave(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.injectDragOver = false;
  }

  onInjectDrop(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.injectDragOver = false;

    if (this.injectSaving || this.injectPreparing) {
      return;
    }

    const files = event.dataTransfer?.files;
    if (!files || files.length === 0) {
      return;
    }

    this.setInjectFile(files[0]);
  }

  private setInjectFile(file: File): void {
    const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');

    if (!isPdf) {
      this.notificationService.showError('Solo se permiten archivos PDF');
      return;
    }

    this.injectFile = file;
  }

  validateInjectCodeLocal(): void {
    this.injectCodeError = this.getDraftCodeError(this.injectCode, this.injectingDoc?.id);
  }

  async validateInjectCode(): Promise<void> {
    const normalizedCode = this.normalizeCode(this.injectCode);
    this.injectCode = normalizedCode;
    this.injectCodeError = this.getDraftCodeError(normalizedCode, this.injectingDoc?.id);

    if (this.injectCodeError || !this.injectingDoc) {
      return;
    }

    if (this.toFolderName(normalizedCode) === this.injectingDoc.folder_name) {
      return;
    }

    if (await this.codeExistsInSystem(normalizedCode) && this.normalizeCode(this.injectCode) === normalizedCode) {
      this.injectCodeError = 'Este codigo ya existe en el sistema';
    }
  }

  async executeInject(): Promise<void> {
    if (!this.injectingDoc) {
      this.notificationService.showError('No hay documento seleccionado');
      return;
    }

    if (!this.injectFechaEmision) {
      this.notificationService.showError('La fecha de emision es obligatoria');
      return;
    }

    this.injectCode = this.normalizeCode(this.injectCode);
    this.injectCodeError = this.getDraftCodeError(this.injectCode, this.injectingDoc.id);

    if (this.injectCodeError) {
      return;
    }

    if (
      this.toFolderName(this.injectCode) !== this.injectingDoc.folder_name &&
      await this.codeExistsInSystem(this.injectCode)
    ) {
      this.injectCodeError = 'Este codigo ya existe en el sistema';
      return;
    }

    const needsPdfSelection = !this.hasImportedPdf(this.injectingDoc) || this.requiresPdfReselection(this.injectingDoc);
    if (needsPdfSelection && !this.injectFile) {
      this.notificationService.showError('Selecciona un archivo PDF para continuar');
      return;
    }

    const docIndex = this.documents.findIndex(doc => doc.id === this.injectingDoc?.id);
    if (docIndex === -1) {
      this.notificationService.showError('No se encontro el borrador a actualizar');
      return;
    }

    const draft = this.documents[docIndex];
    const nextFolderName = this.toFolderName(this.injectCode);
    const previousFolderName = draft.folder_name;
    const previousFechaEmision = draft.fecha_emision;

    this.injectSaving = true;

    try {
      if (draft.qr_id) {
        if (previousFolderName !== nextFolderName) {
          await this.executeWithRetry(
            () => firstValueFrom(this.docqrService.updateFolderName(draft.qr_id!, nextFolderName)),
            `Actualizando codigo ${this.getCodeDisplay(draft.folder_name)}`
          );
        }

        if (previousFechaEmision !== this.injectFechaEmision) {
          await this.executeWithRetry(
            () => firstValueFrom(this.docqrService.bulkUpdateFechaEmision(draft.qr_id!, this.injectFechaEmision)),
            `Actualizando fecha ${this.getCodeDisplay(draft.folder_name)}`
          );
        }

        if (this.injectFile) {
          await this.executeWithRetry(
            () => firstValueFrom(this.docqrService.bulkInjectPdf(draft.qr_id!, this.injectFile!)),
            `Subiendo PDF de ${this.getCodeDisplay(draft.folder_name)}`
          );
          draft.has_server_pdf = true;
        }
      }

      draft.folder_name = nextFolderName;
      draft.fecha_emision = this.injectFechaEmision;

      if (this.injectFile) {
        draft.file = this.injectFile;
        draft.original_filename = this.injectFile.name;
        draft.file_size = this.injectFile.size;
        await this.saveDraftFile(draft.id, this.injectFile);
      }

      this.saveDraftsToStorage();
      this.applySearch();
      this.closeInjectModal();
      this.notificationService.showSuccess('Datos del borrador actualizados');
    } catch (error: any) {
      console.error('Error updating draft data:', error);
      const message = this.getErrorMessage(error, 'Error al actualizar los datos del borrador');
      this.notificationService.showError(message);
    } finally {
      this.injectSaving = false;
    }
  }

  private async ensureServerDraft(doc: DraftDocument): Promise<void> {
    if (doc.qr_id) {
      return;
    }

    const response = await this.executeWithRetry(
      () => firstValueFrom(this.docqrService.bulkCreateRow(doc.folder_name, doc.fecha_emision)),
      `Creando borrador ${this.getCodeDisplay(doc.folder_name)}`
    );

    doc.server_id = response.data.id;
    doc.qr_id = response.data.qr_id;
    doc.qr_url = response.data.qr_url;
    doc.view_url = response.data.qr_url;
    doc.qr_image_url = response.data.qr_image_url;
    doc.status = 'uploaded';
    doc.render_mode = 'original';
    doc.pdf_url = null;
    doc.final_pdf_url = null;
  }

  private async refreshDraftFromServer(doc: DraftDocument): Promise<void> {
    if (!doc.qr_id) {
      return;
    }

    try {
      const response = await this.executeWithRetry(
        () => firstValueFrom(this.docqrService.getDocumentByQrId(doc.qr_id!)),
        `Actualizando estado de ${this.getCodeDisplay(doc.folder_name)}`,
        1,
        false
      );
      const serverDoc = response?.data;

      if (!serverDoc) {
        return;
      }

      doc.server_id = serverDoc.id;
      doc.folder_name = serverDoc.folder_name;
      doc.qr_url = serverDoc.qr_url;
      doc.view_url = serverDoc.view_url || serverDoc.qr_url;
      doc.qr_image_url = serverDoc.qr_image_url;
      doc.status = serverDoc.status;
      doc.render_mode = serverDoc.render_mode;
      doc.pdf_url = serverDoc.pdf_url;
      doc.final_pdf_url = serverDoc.final_pdf_url;
      doc.has_server_pdf = Boolean(serverDoc.original_filename || serverDoc.final_pdf_url || serverDoc.pdf_url);

      if (serverDoc.original_filename) {
        doc.original_filename = serverDoc.original_filename;
      }
      if (serverDoc.file_size) {
        doc.file_size = serverDoc.file_size;
      }

      this.saveDraftsToStorage();
      this.applySearch();
    } catch (error) {
      console.error('Error refreshing draft from server:', error);
    }
  }

  async copyDraftLink(doc: DraftDocument): Promise<void> {
    try {
      this.copyingLinkDraftId = doc.id;
      await this.prepareDraftForManualQrFlow(doc);

      if (!doc.qr_id && !doc.qr_url) {
        this.notificationService.showError('No hay link disponible para este borrador');
        return;
      }

      const url = doc.qr_url || this.docqrService.getViewUrl(doc.qr_id!);

      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(url);
      } else {
        const textArea = document.createElement('textarea');
        textArea.value = url;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
      }

      this.notificationService.showSuccess('Link copiado');
    } catch (error) {
      console.error('Error copying link:', error);
      this.notificationService.showError(this.getErrorMessage(error, 'No se pudo copiar el link'));
    } finally {
      this.copyingLinkDraftId = null;
    }
  }

  async copyDraftQr(doc: DraftDocument): Promise<void> {
    try {
      this.copyingQrDraftId = doc.id;
      await this.prepareDraftForManualQrFlow(doc);

      if (!doc.qr_image_url) {
        this.notificationService.showError('No hay imagen QR disponible para este borrador');
        return;
      }

      await this.copyQrImage(doc.qr_image_url);
      this.notificationService.showSuccess('QR copiado al portapapeles');
    } catch (error) {
      console.error('Error copying QR:', error);
      this.notificationService.showError(this.getErrorMessage(error, 'No se pudo copiar el QR'));
    } finally {
      this.copyingQrDraftId = null;
    }
  }

  async copyInjectModalQr(): Promise<void> {
    if (!this.injectingDoc?.qr_image_url) {
      this.notificationService.showError('No hay QR disponible todavia');
      return;
    }

    try {
      await this.copyQrImage(this.injectingDoc.qr_image_url);
      this.notificationService.showSuccess('QR copiado al portapapeles');
    } catch (error) {
      console.error('Error copying QR from modal:', error);
      this.notificationService.showError('No se pudo copiar el QR');
    }
  }

  async downloadDraftQr(doc: DraftDocument, resolution: 'original' | 'hd' = 'hd'): Promise<void> {
    try {
      await this.prepareDraftForManualQrFlow(doc);
      await this.downloadQr(doc, resolution);
    } catch (error) {
      console.error('Error downloading draft QR:', error);
      this.notificationService.showError(this.getErrorMessage(error, 'No se pudo descargar el QR'));
    }
  }

  async downloadInjectModalQr(resolution: 'original' | 'hd' = 'hd'): Promise<void> {
    if (!this.injectingDoc) {
      return;
    }

    try {
      await this.downloadQr(this.injectingDoc, resolution);
    } catch (error) {
      console.error('Error downloading modal QR:', error);
      this.notificationService.showError('No se pudo descargar el QR');
    }
  }

  async removeDraft(doc: DraftDocument): Promise<void> {
    this.openConfirmationModal({
      title: 'Eliminar borrador',
      message: `Se eliminara el borrador ${doc.folder_name}. Esta accion no se puede deshacer.`,
      confirmLabel: 'Eliminar',
      confirmTone: 'danger',
      onConfirm: () => void this.executeRemoveDraft(doc)
    });
  }

  private async executeRemoveDraft(doc: DraftDocument): Promise<void> {
    try {
      this.removingDraftId = doc.id;
      if (doc.server_id) {
        await this.executeWithRetry(
          () => firstValueFrom(this.docqrService.deleteDocument(doc.server_id!)),
          `Eliminando ${this.getCodeDisplay(doc.folder_name)}`
        );
      } else if (doc.qr_id) {
        const backendDocument = await this.executeWithRetry(
          () => firstValueFrom(this.docqrService.getDocumentByQrId(doc.qr_id!)),
          `Consultando ${this.getCodeDisplay(doc.folder_name)}`,
          1,
          false
        );
        if (backendDocument?.data?.id) {
          await this.executeWithRetry(
            () => firstValueFrom(this.docqrService.deleteDocument(backendDocument.data.id)),
            `Eliminando ${this.getCodeDisplay(doc.folder_name)}`
          );
        }
      }
    } catch (error) {
      console.error('Error deleting synced draft from server:', error);
      this.notificationService.showWarning(this.getErrorMessage(error, 'No se pudo eliminar el borrador en el servidor; se eliminara solo localmente'));
    } finally {
      this.removingDraftId = null;
    }

    this.documents = this.documents.filter(item => item.id !== doc.id);
    await this.deleteDraftFile(doc.id);
    this.saveDraftsToStorage();
    this.applySearch();
    this.notificationService.showSuccess('Borrador eliminado');
  }

  async saveAllDrafts(): Promise<void> {
    if (this.documents.length === 0) {
      this.notificationService.showWarning('No hay borradores para guardar');
      return;
    }

    const draftsWithLostPdf = this.documents.filter(doc => this.requiresPdfReselection(doc));
    if (draftsWithLostPdf.length > 0) {
      this.notificationService.showError(
        `Hay ${draftsWithLostPdf.length} documento(s) con PDF importado pero sin archivo recuperable. Reimportalos desde Editar datos antes de guardar.`
      );
      return;
    }

    const withoutPdf = this.documents.filter(doc => !this.hasImportedPdf(doc));
    if (withoutPdf.length > 0) {
      this.openConfirmationModal({
        title: 'Continuar sin PDF',
        message: `Hay ${withoutPdf.length} documento(s) sin PDF firmado. Se guardaran los codigos y el QR quedara listo para subir el PDF despues.`,
        confirmLabel: 'Continuar',
        confirmTone: 'primary',
        onConfirm: () => void this.executeSaveAllDrafts()
      });
      return;
    }

    await this.executeSaveAllDrafts();
  }

  private async executeSaveAllDrafts(): Promise<void> {
    this.savingAll = true;

    let completed = 0;
    let errors = 0;
    let rollbacks = 0;
    const successfulDraftIds: string[] = [];
    const errorDetails: string[] = [];

    for (const doc of this.documents) {
      if (doc.qr_id) {
        completed++;
        successfulDraftIds.push(doc.id);
        continue;
      }

      let createdDocument: { success: boolean; message: string; data: any } | null = null;

      try {
        createdDocument = await this.executeWithRetry(
          () => firstValueFrom(this.docqrService.bulkCreateRow(doc.folder_name, doc.fecha_emision)),
          `Creando ${this.getCodeDisplay(doc.folder_name)}`
        );

        if (doc.file) {
          await this.executeWithRetry(
            () => firstValueFrom(this.docqrService.bulkInjectPdf(createdDocument!.data.qr_id, doc.file!)),
            `Subiendo PDF de ${this.getCodeDisplay(doc.folder_name)}`
          );
        }

        successfulDraftIds.push(doc.id);
        completed++;
      } catch (error) {
        console.error('Error saving draft:', doc.folder_name, error);
        errors++;
        errorDetails.push(`${this.getCodeDisplay(doc.folder_name)}: ${this.getErrorMessage(error, 'No se pudo guardar')}`);

        if (createdDocument?.data?.id) {
          try {
            await this.executeWithRetry(
              () => firstValueFrom(this.docqrService.deleteDocument(createdDocument!.data.id)),
              `Revirtiendo ${this.getCodeDisplay(doc.folder_name)}`,
              1,
              false
            );
            rollbacks++;
          } catch (rollbackError) {
            console.error('Error rolling back partial save:', rollbackError);
          }
        }
      }
    }

    await this.finalizeSave(completed, errors, rollbacks, successfulDraftIds, errorDetails);
  }

  private async finalizeSave(
    completed: number,
    errors: number,
    rollbacks: number,
    successfulDraftIds: string[],
    errorDetails: string[]
  ): Promise<void> {
    this.savingAll = false;

    if (successfulDraftIds.length > 0) {
      await this.removeSavedDrafts(successfulDraftIds);
    }

    if (completed > 0) {
      this.notificationService.showSuccess(
        `${completed} documento(s) guardado(s) exitosamente` +
        (errors > 0 ? `. ${errors} error(es).` : '') +
        (rollbacks > 0 ? ` ${rollbacks} alta(s) parcial(es) se revirtieron.` : '')
      );

      if (errorDetails.length > 0) {
        this.notificationService.showWarning(
          `Documentos con error: ${errorDetails.slice(0, 3).join(' | ')}`,
          9000
        );
      }
      return;
    }

    this.notificationService.showError(
      errorDetails.length > 0
        ? `Error al guardar documentos. ${errorDetails.slice(0, 2).join(' | ')}`
        : 'Error al guardar documentos'
    );
  }

  private async removeSavedDrafts(successfulDraftIds: string[]): Promise<void> {
    this.documents = this.documents.filter(doc => !successfulDraftIds.includes(doc.id));
    await Promise.all(successfulDraftIds.map(id => this.deleteDraftFile(id)));

    if (this.documents.length === 0) {
      await this.clearDraftsFromStorage();
    } else {
      this.saveDraftsToStorage();
    }

    this.applySearch();
  }

  formatDate(date: string | null): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('es-ES');
  }

  formatFileSize(bytes: number | null | undefined): string {
    if (!bytes) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  }

  onToggleSidebar(): void {
    this.sidebarOpen = !this.sidebarOpen;
  }

  onCloseSidebar(): void {
    this.sidebarOpen = false;
  }

  hasImportedPdf(doc: DraftDocument): boolean {
    return Boolean(doc.original_filename || doc.file || doc.has_server_pdf);
  }

  hasGeneratedQr(doc: DraftDocument): boolean {
    return Boolean(doc.qr_id || doc.qr_url || doc.qr_image_url);
  }

  requiresPdfReselection(doc: DraftDocument): boolean {
    return Boolean((doc.original_filename || doc.file) && !doc.file && !doc.has_server_pdf);
  }

  getPdfActionLabel(doc: DraftDocument): string {
    if (!this.hasImportedPdf(doc)) {
      return 'Subir PDF con firma';
    }

    if (this.requiresPdfReselection(doc)) {
      return 'Reimportar PDF con firma';
    }

    return 'Actualizar PDF con firma';
  }

  getQrActionLabel(doc: DraftDocument): string {
    return this.hasGeneratedQr(doc) ? 'Copiar QR' : 'Generar QR';
  }

  getQrStatusLabel(doc: DraftDocument): string {
    return this.hasGeneratedQr(doc) ? 'QR listo' : 'QR pendiente';
  }

  getQrPreviewUrl(doc: DraftDocument | null): string {
    if (!doc?.qr_image_url) {
      return '';
    }

    const separator = doc.qr_image_url.includes('?') ? '&' : '?';
    return `${doc.qr_image_url}${separator}t=${Date.now()}`;
  }

  getCodeDisplay(folderName: string): string {
    return this.toCodeInput(folderName);
  }

  private normalizeCode(value: string): string {
    const normalizedValue = (value || '').trim().toUpperCase();
    return normalizedValue.replace(/^IN-?/i, '');
  }

  private toFolderName(code: string): string {
    const normalizedCode = this.normalizeCode(code);
    return normalizedCode ? `IN-${normalizedCode}` : '';
  }

  private toCodeInput(folderName: string): string {
    return (folderName || '').replace(/^IN-/i, '');
  }

  private getCodeFormatError(code: string): string {
    if (!code) {
      return 'Codigo requerido';
    }
    if (code.length < 1) {
      return 'Codigo muy corto';
    }
    if (!/^[A-Z0-9-]+$/i.test(code)) {
      return 'Formato invalido';
    }
    return '';
  }

  private getDraftCodeError(code: string, excludeDraftId?: string): string {
    const normalizedCode = this.normalizeCode(code);
    const formatError = this.getCodeFormatError(normalizedCode);
    if (formatError) {
      return formatError;
    }

    const duplicatedDraft = this.documents.find(doc =>
      doc.id !== excludeDraftId &&
      doc.folder_name === this.toFolderName(normalizedCode)
    );

    if (duplicatedDraft) {
      return 'Este codigo ya existe en los borradores';
    }

    return '';
  }

  private getBulkCodeError(entryId: string, code: string): string {
    const normalizedCode = this.normalizeCode(code);
    const formatError = this.getCodeFormatError(normalizedCode);
    if (formatError) {
      return formatError;
    }

    const duplicatedInModal = this.bulkEntries.find(
      entry =>
        entry.id !== entryId &&
        this.normalizeCode(entry.code) === normalizedCode
    );

    if (duplicatedInModal) {
      return 'Codigo duplicado en esta carga';
    }

    const duplicatedDraft = this.documents.find(doc => doc.folder_name === this.toFolderName(normalizedCode));
    if (duplicatedDraft) {
      return 'Ya existe en borradores';
    }

    return '';
  }

  private async executeWithRetry<T>(
    operation: () => Promise<T>,
    actionLabel: string,
    retries: number = 2,
    notifyRetry: boolean = true
  ): Promise<T> {
    let attempt = 0;

    while (true) {
      try {
        return await operation();
      } catch (error) {
        if (attempt >= retries || !this.shouldRetryRequest(error)) {
          throw error;
        }

        attempt++;

        if (notifyRetry) {
          this.notificationService.showWarning(
            `${actionLabel}: reintentando automaticamente (${attempt}/${retries})...`,
            2500
          );
        }

        await this.delay(450 * attempt);
      }
    }
  }

  private shouldRetryRequest(error: any): boolean {
    const status = error?.status;
    return [0, 408, 425, 429, 500, 502, 503, 504].includes(status);
  }

  private getErrorMessage(error: any, fallback: string): string {
    const validationErrors = error?.error?.errors;
    if (validationErrors) {
      const firstValidationMessage = Object.values(validationErrors).flat().find(Boolean);
      if (typeof firstValidationMessage === 'string') {
        return firstValidationMessage;
      }
    }

    if (typeof error?.error?.message === 'string' && error.error.message.trim()) {
      return error.error.message;
    }

    if (error?.status === 0) {
      return `${fallback}. No se pudo conectar con el servidor.`;
    }

    if (error?.status === 429) {
      return `${fallback}. Hay mucha carga en el servidor; intenta nuevamente en unos segundos.`;
    }

    if (error?.status >= 500) {
      return `${fallback}. El servidor respondio con error interno.`;
    }

    if (typeof error?.message === 'string' && error.message.trim()) {
      return error.message;
    }

    return fallback;
  }

  private delay(ms: number): Promise<void> {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  closeConfirmationModal(): void {
    this.confirmationModal = {
      open: false,
      title: '',
      message: '',
      confirmLabel: 'Confirmar',
      confirmTone: 'primary'
    };
    this.pendingConfirmationAction = null;
  }

  async confirmModalAction(): Promise<void> {
    const action = this.pendingConfirmationAction;
    this.closeConfirmationModal();

    if (!action) {
      return;
    }

    await action();
  }

  private openConfirmationModal(config: {
    title: string;
    message: string;
    confirmLabel: string;
    confirmTone: 'danger' | 'primary';
    onConfirm: () => void | Promise<void>;
  }): void {
    this.confirmationModal = {
      open: true,
      title: config.title,
      message: config.message,
      confirmLabel: config.confirmLabel,
      confirmTone: config.confirmTone
    };
    this.pendingConfirmationAction = config.onConfirm;
  }

  private async codeExistsInSystem(code: string): Promise<boolean> {
    try {
      const response = await this.executeWithRetry(
        () => firstValueFrom(this.docqrService.bulkCheckCode(this.toFolderName(code))),
        'Validando codigo',
        1,
        false
      );
      return response.exists;
    } catch (error) {
      console.error('Error validating code in system:', error);
      return false;
    }
  }

  get totalDrafts(): number {
    return this.documents.length;
  }

  get draftsWithPdf(): number {
    return this.documents.filter(doc => this.hasImportedPdf(doc)).length;
  }

  get draftsWithoutPdf(): number {
    return this.documents.filter(doc => !this.hasImportedPdf(doc)).length;
  }

  private async prepareDraftForManualQrFlow(doc: DraftDocument): Promise<void> {
    if (!doc.qr_id) {
      await this.ensureServerDraft(doc);
    }

    if (!doc.qr_image_url || !doc.qr_url) {
      await this.refreshDraftFromServer(doc);
    }

    this.saveDraftsToStorage();
    this.applySearch();
  }

  private async copyQrImage(qrImageUrl: string): Promise<void> {
    const response = await fetch(this.getUrlWithCacheBuster(qrImageUrl));
    if (!response.ok) {
      throw new Error('No se pudo obtener la imagen del QR');
    }

    const blob = await response.blob();
    const ClipboardItemCtor = (window as any).ClipboardItem;

    if (!navigator.clipboard?.write || !ClipboardItemCtor) {
      throw new Error('Este navegador no permite copiar imagenes al portapapeles');
    }

    await navigator.clipboard.write([new ClipboardItemCtor({ [blob.type || 'image/png']: blob })]);
  }

  private async downloadQr(doc: DraftDocument, resolution: 'original' | 'hd'): Promise<void> {
    if (!doc.qr_id) {
      throw new Error('No hay QR generado para este borrador');
    }

    const baseUrl = doc.qr_image_url || `/api/files/qr/${doc.qr_id}`;
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set('resolution', resolution);
    url.searchParams.set('download', 'true');

    const response = await fetch(url.toString());
    if (!response.ok) {
      throw new Error('No se pudo descargar el QR');
    }

    const blob = await response.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = resolution === 'hd'
      ? `qr-${doc.qr_id}-1024x1024.png`
      : `qr-${doc.qr_id}.png`;

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(downloadUrl);
    this.notificationService.showSuccess(
      resolution === 'hd'
        ? 'QR en alta resolucion descargado exitosamente'
        : 'QR descargado exitosamente'
    );
  }

  private getUrlWithCacheBuster(url: string): string {
    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}t=${Date.now()}`;
  }
}
