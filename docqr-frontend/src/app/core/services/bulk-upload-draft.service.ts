import { Injectable } from '@angular/core';

/**
 * Estados posibles de una fila del lote masivo
 */
export type BulkRowStatus = 'draft' | 'ready' | 'validating' | 'processing' | 'success' | 'error';
export type BulkEmbedStatus = 'pending' | 'sample' | 'processing' | 'completed' | 'error';
export type BulkReviewStatus = 'pending' | 'reviewed' | 'manually_corrected';

/**
 * Resultado de una fila procesada exitosamente
 */
export interface BulkRowProcessResult {
  qr_id: string;
  qr_url: string;
  pdf_url: string;
  qr_image_url: string;
  folder_name: string;
  emission_date: string;
  original_filename: string;
}

/**
 * Fila del borrador del lote masivo
 */
export interface BulkDraftRow {
  rowId: string;
  code: string;
  checkingCode: boolean;
  codeExists: boolean;
  codeError: string | null;
  emissionDate: string;
  fileName: string;
  fileType: string;
  fileLastModified: number;
  file: File | null;
  pdfDuplicateError: string | null;
  status: BulkRowStatus;
  errorMessage: string | null;
  embedStatus: BulkEmbedStatus;
  embedErrorMessage: string | null;
  finalPdfUrl: string | null;
  reviewStatus: BulkReviewStatus;
  result: BulkRowProcessResult | null;
}

/**
 * Estado completo del borrador del lote masivo
 */
export interface BulkDraftState {
  generatedCount: number;
  rows: BulkDraftRow[];
  savedAt: string;
}

interface PersistedBulkDraftRow {
  rowId: string;
  code: string;
  emissionDate: string;
  fileName: string;
  fileType: string;
  fileLastModified: number;
  fileBlob: Blob | null;
  status: BulkRowStatus;
  errorMessage: string | null;
  embedStatus: BulkEmbedStatus;
  embedErrorMessage: string | null;
  finalPdfUrl: string | null;
  reviewStatus: BulkReviewStatus;
  result: BulkRowProcessResult | null;
}

interface PersistedBulkDraftState {
  key: string;
  generatedCount: number;
  rows: PersistedBulkDraftRow[];
  savedAt: string;
}

/**
 * Servicio para persistir el borrador del módulo masivo en IndexedDB
 */
@Injectable({
  providedIn: 'root'
})
export class BulkUploadDraftService {
  private readonly dbName = 'docqr-bulk-upload';
  private readonly storeName = 'drafts';
  private readonly draftKey = 'bulk-in-upload-v1';
  private readonly dbVersion = 1;

  /**
   * Verificar si existe un borrador almacenado
   */
  async hasDraft(): Promise<boolean> {
    const draft = await this.loadDraft();
    return !!draft;
  }

  /**
   * Guardar borrador del lote
   */
  async saveDraft(state: BulkDraftState): Promise<void> {
    const database = await this.openDatabase();
    const transaction = database.transaction(this.storeName, 'readwrite');
    const store = transaction.objectStore(this.storeName);

    const payload: PersistedBulkDraftState = {
      key: this.draftKey,
      generatedCount: state.generatedCount,
      savedAt: state.savedAt,
      rows: state.rows.map((row) => ({
        rowId: row.rowId,
        code: row.code,
        emissionDate: row.emissionDate,
        fileName: row.fileName,
        fileType: row.fileType,
        fileLastModified: row.fileLastModified,
        fileBlob: row.file ?? null,
        status: row.status,
        errorMessage: row.errorMessage,
        embedStatus: row.embedStatus,
        embedErrorMessage: row.embedErrorMessage,
        finalPdfUrl: row.finalPdfUrl,
        reviewStatus: row.reviewStatus,
        result: row.result
      }))
    };

    await this.wrapRequest(store.put(payload));
    await this.waitForTransaction(transaction);
    database.close();
  }

  /**
   * Cargar borrador del lote
   */
  async loadDraft(): Promise<BulkDraftState | null> {
    const database = await this.openDatabase();
    const transaction = database.transaction(this.storeName, 'readonly');
    const store = transaction.objectStore(this.storeName);
    const persistedDraft = await this.wrapRequest<PersistedBulkDraftState | undefined>(
      store.get(this.draftKey)
    );

    await this.waitForTransaction(transaction);
    database.close();

    if (!persistedDraft) {
      return null;
    }

    return {
      generatedCount: persistedDraft.generatedCount,
      savedAt: persistedDraft.savedAt,
      rows: persistedDraft.rows.map((row) => ({
        rowId: row.rowId,
        code: row.code,
        checkingCode: false,
        codeExists: false,
        codeError: null,
        emissionDate: row.emissionDate,
        fileName: row.fileName,
        fileType: row.fileType,
        fileLastModified: row.fileLastModified,
        file: row.fileBlob
          ? new File([row.fileBlob], row.fileName, {
              type: row.fileType || 'application/pdf',
              lastModified: row.fileLastModified || Date.now()
            })
          : null,
        pdfDuplicateError: null,
        status: row.status,
        errorMessage: row.errorMessage,
        embedStatus: row.embedStatus ?? 'pending',
        embedErrorMessage: row.embedErrorMessage ?? null,
        finalPdfUrl: row.finalPdfUrl ?? null,
        reviewStatus: row.reviewStatus ?? 'pending',
        result: row.result
      }))
    };
  }

  /**
   * Eliminar el borrador almacenado
   */
  async clearDraft(): Promise<void> {
    const database = await this.openDatabase();
    const transaction = database.transaction(this.storeName, 'readwrite');
    const store = transaction.objectStore(this.storeName);

    await this.wrapRequest(store.delete(this.draftKey));
    await this.waitForTransaction(transaction);
    database.close();
  }

  /**
   * Abrir la base de datos del navegador
   */
  private openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
      if (typeof indexedDB === 'undefined') {
        reject(new Error('IndexedDB no está disponible en este navegador.'));
        return;
      }

      const request = indexedDB.open(this.dbName, this.dbVersion);

      request.onerror = () => reject(new Error('No se pudo abrir IndexedDB.'));

      request.onupgradeneeded = () => {
        const database = request.result;
        if (!database.objectStoreNames.contains(this.storeName)) {
          database.createObjectStore(this.storeName, { keyPath: 'key' });
        }
      };

      request.onsuccess = () => resolve(request.result);
    });
  }

  /**
   * Resolver una operación de IndexedDB como promesa
   */
  private wrapRequest<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('Error en IndexedDB.'));
    });
  }

  /**
   * Esperar a que la transacción termine
   */
  private waitForTransaction(transaction: IDBTransaction): Promise<void> {
    return new Promise((resolve, reject) => {
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error ?? new Error('Error en la transacción.'));
      transaction.onabort = () => reject(transaction.error ?? new Error('La transacción fue cancelada.'));
    });
  }
}
