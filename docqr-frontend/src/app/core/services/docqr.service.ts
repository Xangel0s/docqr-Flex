import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';

/**
 * Interfaz para respuesta de upload
 */
export interface UploadResponse {
  success: boolean;
  message: string;
  data: {
    qr_id: string;
    qr_url: string;
    pdf_url: string;
    qr_image_url: string;
    folder_name: string;
    original_filename: string;
  };
}

/**
 * Interfaz para respuesta de embed
 */
export interface EmbedResponse {
  success: boolean;
  message: string;
  data: {
    final_pdf_url: string;
    status: string;
    qr_position: {
      x: number;
      y: number;
      width: number;
      height: number;
    };
  };
}

/**
 * Interfaz para documento
 */
export interface Document {
  id: number;
  qr_id: string;
  folder_name: string;
  original_filename: string;
  file_size: number;
  qr_position: any;
  status: string;
  scan_count: number;
  last_scanned_at: string | null;
  created_at: string;
  qr_url: string;
  pdf_url: string;
  pdf_original_url?: string; // URL del PDF original (para editor)
  qr_image_url: string;
  final_pdf_url: string | null;
}

/**
 * Interfaz para respuesta de documentos
 */
export interface DocumentsResponse {
  success: boolean;
  data: Document[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

/**
 * Interfaz para estadísticas
 */
export interface StatsResponse {
  success: boolean;
  data: {
    total_documents: number;
    total_scans: number;
    scans_last_30_days: number;
    completed_documents: number;
    pending_documents: number;
    last_upload: string | null;
    activity_by_folder: Array<{
      folder_name: string;
      document_count: number;
      total_scans: number;
    }>;
    recent_documents: Array<{
      id: number;
      original_filename: string;
      folder_name: string;
      scan_count: number;
      last_scanned_at: string | null;
      status: string;
    }>;
  };
}

/**
 * Servicio para comunicación con la API DocQR
 */
@Injectable({
  providedIn: 'root'
})
export class DocqrService {
  private apiUrl: string;

  constructor(private http: HttpClient) {
    let baseUrl = environment.apiUrl;
    
    if (baseUrl.startsWith('http://') && window.location.protocol === 'https:') {
      this.apiUrl = '/api';
    } else if (baseUrl.startsWith('http://localhost') && window.location.protocol === 'https:') {
      this.apiUrl = '/api';
    } else {
      baseUrl = baseUrl.replace(/\/api\/?$/, '');
      this.apiUrl = `${baseUrl}/api`;
    }
  }

  /**
   * Subir PDF y generar QR
   */
  uploadPdf(file: File, folderName: string): Observable<UploadResponse> {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('folder_name', folderName);

    return this.http.post<UploadResponse>(`${this.apiUrl}/upload`, formData);
  }

  /**
   * Embebir QR en PDF con posición
   */
  embedQr(qrId: string, position: { x: number; y: number; width: number; height: number }, pageNumber: number = 1): Observable<EmbedResponse> {
    return this.http.put<EmbedResponse>(`${this.apiUrl}/embed`, {
      qr_id: qrId,
      page_number: pageNumber,
      ...position
    });
  }

  /**
   * Obtener lista de documentos
   */
  getDocuments(
    folder?: string, 
    search?: string, 
    page: number = 1,
    filters?: {
      type?: string;
      status?: string;
      dateFrom?: string;
      dateTo?: string;
      scansFilter?: string;
      sortBy?: string;
      sortOrder?: 'asc' | 'desc';
    }
  ): Observable<DocumentsResponse> {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', '15');

    if (folder) {
      params = params.set('folder', folder);
    }

    if (search) {
      params = params.set('search', search);
    }

    // Aplicar filtros adicionales
    if (filters) {
      if (filters.type && filters.type !== 'all') {
        params = params.set('type', filters.type);
      }
      if (filters.status && filters.status !== 'all') {
        params = params.set('status', filters.status);
      }
      if (filters.dateFrom) {
        params = params.set('date_from', filters.dateFrom);
      }
      if (filters.dateTo) {
        params = params.set('date_to', filters.dateTo);
      }
      if (filters.scansFilter && filters.scansFilter !== 'all') {
        params = params.set('scans_filter', filters.scansFilter);
      }
      if (filters.sortBy) {
        params = params.set('sort_by', filters.sortBy);
      }
      if (filters.sortOrder) {
        params = params.set('sort_order', filters.sortOrder);
      }
    }

    return this.http.get<DocumentsResponse>(`${this.apiUrl}/documents`, { params });
  }

  /**
   * Obtener un documento específico por ID numérico
   */
  getDocumentById(id: number): Observable<{ success: boolean; data: Document }> {
    return this.http.get<{ success: boolean; data: Document }>(`${this.apiUrl}/documents/${id}`);
  }

  /**
   * Obtener un documento por qr_id
   */
  getDocumentByQrId(qrId: string): Observable<{ success: boolean; data: Document }> {
    return this.http.get<{ success: boolean; data: Document }>(`${this.apiUrl}/documents/qr/${qrId}`);
  }


  /**
   * Verificar si un código (folder_name) ya existe
   */
  checkCodeExists(folderName: string): Observable<{
    success: boolean;
    exists: boolean;
    message: string;
  }> {
    return this.http.get<{
      success: boolean;
      exists: boolean;
      message: string;
    }>(`${this.apiUrl}/documents/check-code`, {
      params: { folder_name: folderName }
    });
  }

  /**
   * Crear documento y generar QR sin PDF (para flujo "Adjuntar")
   */
  createDocumentWithoutPdf(folderName: string): Observable<{
    success: boolean;
    message: string;
    data: {
      qr_id: string;
      qr_url: string;
      qr_image_url: string;
      folder_name: string;
    };
  }> {
    return this.http.post<{
      success: boolean;
      message: string;
      data: {
        qr_id: string;
        qr_url: string;
        qr_image_url: string;
        folder_name: string;
      };
    }>(`${this.apiUrl}/documents/create`, {
      folder_name: folderName
    });
  }

  /**
   * Adjuntar PDF a un documento existente (sin procesar)
   */
  attachPdf(qrId: string, file: File): Observable<{
    success: boolean;
    message: string;
    data: {
      pdf_url: string;
      original_filename: string;
      file_size: number;
    };
  }> {
    // Validar que el archivo existe y tiene contenido
    if (!file || file.size === 0) {
      throw new Error('El archivo está vacío o no es válido');
    }

    const formData = new FormData();
    formData.append('file', file, file.name);

    // Log para debugging (solo en desarrollo)
    if (!environment.production) {
      console.log('Enviando archivo:', {
        name: file.name,
        size: file.size,
        sizeMB: (file.size / (1024 * 1024)).toFixed(2),
        type: file.type,
        lastModified: new Date(file.lastModified).toISOString()
      });
    }

    return this.http.post<{
      success: boolean;
      message: string;
      data: {
        pdf_url: string;
        original_filename: string;
        file_size: number;
      };
    }>(`${this.apiUrl}/documents/qr/${qrId}/attach-pdf`, formData, {
      // No establecer Content-Type - dejar que el navegador lo establezca automáticamente con el boundary
      // Esto es crítico para FormData multipart/form-data
    });
  }

  /**
   * Eliminar documento
   */
  /**
   * Actualizar nombre de carpeta de un documento
   */
  updateFolderName(qrId: string, folderName: string): Observable<{ success: boolean; message: string; data?: any }> {
    return this.http.put<{ success: boolean; message: string; data?: any }>(
      `${this.apiUrl}/documents/qr/${qrId}/folder-name`,
      { folder_name: folderName }
    );
  }

  deleteDocument(id: number): Observable<{ success: boolean; message: string }> {
    return this.http.delete<{ success: boolean; message: string }>(`${this.apiUrl}/documents/${id}`);
  }

  /**
   * Obtener todos los documentos sin paginación para exportar
   */
  getAllDocumentsForExport(
    search?: string,
    filters?: {
      type?: string;
      status?: string;
      dateFrom?: string;
      dateTo?: string;
      scansFilter?: string;
      sortBy?: string;
      sortOrder?: 'asc' | 'desc';
    }
  ): Observable<DocumentsResponse> {
    let params = new HttpParams()
      .set('per_page', '10000'); // Número grande para obtener todos

    if (search) {
      params = params.set('search', search);
    }

    // Aplicar filtros adicionales
    if (filters) {
      if (filters.type && filters.type !== 'all') {
        params = params.set('type', filters.type);
      }
      if (filters.status && filters.status !== 'all') {
        params = params.set('status', filters.status);
      }
      if (filters.dateFrom) {
        params = params.set('date_from', filters.dateFrom);
      }
      if (filters.dateTo) {
        params = params.set('date_to', filters.dateTo);
      }
      if (filters.scansFilter && filters.scansFilter !== 'all') {
        params = params.set('scans_filter', filters.scansFilter);
      }
      if (filters.sortBy) {
        params = params.set('sort_by', filters.sortBy);
      }
      if (filters.sortOrder) {
        params = params.set('sort_order', filters.sortOrder);
      }
    }

    return this.http.get<DocumentsResponse>(`${this.apiUrl}/documents`, { params });
  }

  /**
   * Obtener estadísticas
   */
  getStats(): Observable<StatsResponse> {
    return this.http.get<StatsResponse>(`${this.apiUrl}/documents/stats`);
  }

  /**
   * Obtener URL de visualización del PDF
   */
  getViewUrl(qrId: string): string {
    return `${this.apiUrl}/view/${qrId}`;
  }

  /**
   * Obtener estado de compresión del sistema
   */
  getCompressionStatus(): Observable<{
    success: boolean;
    data: {
      needs_compression: boolean;
      pending_count: number;
      total_size_mb: number;
      estimated_savings_mb: number;
      by_type: { [key: string]: number };
      message: string;
    };
  }> {
    return this.http.get<{
      success: boolean;
      data: {
        needs_compression: boolean;
        pending_count: number;
        total_size_mb: number;
        estimated_savings_mb: number;
        by_type: { [key: string]: number };
        message: string;
      };
    }>(`${this.apiUrl}/system/compression-status`);
  }

  /**
   * Listar grupos de documentos comprimibles
   */
  getCompressionList(months: number = 6): Observable<{
    success: boolean;
    data: Array<{
      type: string;
      month: string;
      month_formatted: string;
      count: number;
      total_size_mb: number;
      documents: any[];
    }>;
    total_groups: number;
    total_documents: number;
  }> {
    return this.http.get<{
      success: boolean;
      data: Array<{
        type: string;
        month: string;
        month_formatted: string;
        count: number;
        total_size_mb: number;
        documents: any[];
      }>;
      total_groups: number;
      total_documents: number;
    }>(`${this.apiUrl}/compression/list`, {
      params: { months: months.toString() }
    });
  }

  /**
   * Comprimir documentos por tipo y mes
   */
  compressByMonth(type: string, month: string): Observable<{
    success: boolean;
    message: string;
    data?: {
      archive_path: string;
      zip_size_mb: number;
      documents_count: number;
    };
  }> {
    return this.http.post<{
      success: boolean;
      message: string;
      data?: {
        archive_path: string;
        zip_size_mb: number;
        documents_count: number;
      };
    }>(`${this.apiUrl}/compression/compress`, {
      type,
      month
    });
  }

  /**
   * Regenerar QR code con URL actualizada
   * Útil para corregir QRs que tienen URLs con localhost
   */
  regenerateQr(qrId: string): Observable<{ success: boolean; message: string; data: any }> {
    return this.http.post<{ success: boolean; message: string; data: any }>(
      `${this.apiUrl}/documents/qr/${qrId}/regenerate-qr`,
      {}
    );
  }
}

