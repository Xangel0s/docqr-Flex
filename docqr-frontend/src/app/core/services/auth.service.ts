import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, BehaviorSubject, of } from 'rxjs';
import { tap, catchError } from 'rxjs/operators';
import { Router } from '@angular/router';
import { environment } from '../../../environments/environment';

export interface User {
  id: number;
  username: string;
  name: string;
  email?: string;
  role: string;
}

export interface LoginResponse {
  success: boolean;
  message: string;
  data: {
    user: User;
    token?: string;
    access_token?: string;
  };
  // También puede venir a nivel raíz para compatibilidad
  token?: string;
  access_token?: string;
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private apiUrl: string;
  private tokenKey = 'geofal_token';
  private userKey = 'geofal_user';
  
  private currentUserSubject = new BehaviorSubject<User | null>(this.getStoredUser());
  public currentUser$ = this.currentUserSubject.asObservable();

  constructor(
    private http: HttpClient,
    private router: Router
  ) {
    // Detectar si estamos en HTTPS (ngrok) y usar URL relativa para evitar Mixed Content
    // Si apiUrl es absoluto y comienza con http://, y estamos en HTTPS, usar URL relativa
    let baseUrl = environment.apiUrl;
    
    if (baseUrl.startsWith('http://') && window.location.protocol === 'https:') {
      // Usar URL relativa para evitar Mixed Content
      this.apiUrl = '/api';
    } else if (baseUrl.startsWith('http://localhost') && window.location.protocol === 'https:') {
      // Si estamos en HTTPS pero apiUrl apunta a localhost HTTP, usar URL relativa
      this.apiUrl = '/api';
    } else {
      // Asegurar que apiUrl termine con /api si no está presente
      // Remover /api si ya está al final para evitar duplicación
      baseUrl = baseUrl.replace(/\/api\/?$/, '');
      // Agregar /api al final
      this.apiUrl = `${baseUrl}/api`;
    }
  }

  /**
   * Login de usuario
   */
  login(username: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${this.apiUrl}/auth/login`, {
      username,
      password
    }).pipe(
      tap(response => {
        // CRÍTICO: Guardar token y usuario de forma síncrona antes de que el next() se ejecute
        // Esto asegura que cuando el componente verifique isAuthenticated(), el token ya esté guardado
        if (response && response.success && response.data) {
          // CORRECCIÓN: Usar access_token primero, luego token como fallback
          // El backend envía access_token (Sanctum) pero también puede enviar token para compatibilidad
          const token = response.data?.access_token || response.data?.token || response.access_token || response.token;
          
          if (token && typeof token === 'string' && token.length > 0) {
            // Guardar inmediatamente de forma síncrona
            try {
              this.setToken(token);
              if (response.data.user) {
                this.setUser(response.data.user);
                this.currentUserSubject.next(response.data.user);
              }
            } catch (error) {
              if (!environment.production) {
                console.error('Error al guardar token en localStorage:', error);
              }
            }
          } else {
            if (!environment.production) {
              console.error('No se recibió token válido en la respuesta del login:', response);
            }
          }
        } else {
          if (!environment.production) {
            console.error('Respuesta de login inválida:', response);
          }
        }
      }),
      catchError(error => {
        if (!environment.production) {
          console.error('Error en login:', error);
        }
        throw error;
      })
    );
  }

  /**
   * Logout
   */
  logout(): void {
    this.removeToken();
    this.removeUser();
    this.currentUserSubject.next(null);
    this.router.navigate(['/login']);
  }

  /**
   * Verificar si el usuario está autenticado
   */
  isAuthenticated(): boolean {
    const token = this.getToken();
    return !!token;
  }

  /**
   * Obtener token actual
   */
  getToken(): string | null {
    return localStorage.getItem(this.tokenKey);
  }

  /**
   * Obtener usuario actual
   */
  getCurrentUser(): User | null {
    return this.currentUserSubject.value;
  }

  /**
   * Verificar sesión en el servidor
   * No hace logout automático en caso de error de red para evitar interrupciones
   */
  checkAuth(): Observable<{ success: boolean; data?: { user: User } }> {
    const token = this.getToken();
    if (!token) {
      return of({ success: false });
    }

    return this.http.get<{ success: boolean; data?: { user: User } }>(`${this.apiUrl}/auth/me`).pipe(
      tap(response => {
        if (response.success && response.data) {
          // Actualizar usuario en caché (localStorage y BehaviorSubject)
          this.setUser(response.data.user);
          this.currentUserSubject.next(response.data.user);
        } else {
          // Solo hacer logout si el servidor dice explícitamente que el token es inválido
          // No hacer logout por errores de red
          this.logout();
        }
      }),
      catchError((error) => {
        // Solo hacer logout si es un error 401 (no autorizado)
        // Errores de red (0, timeout, etc.) no deben causar logout
        if (error.status === 401) {
          this.logout();
        }
        // Retornar success: false pero no hacer logout automático
        return of({ success: false });
      })
    );
  }

  /**
   * Refrescar datos del usuario desde el servidor
   * Útil cuando se actualiza el perfil del usuario
   */
  refreshUser(): Observable<{ success: boolean; data?: { user: User } }> {
    return this.checkAuth();
  }

  /**
   * Guardar token
   */
  private setToken(token: string): void {
    try {
      if (token && typeof token === 'string' && token.length > 0) {
        localStorage.setItem(this.tokenKey, token);
        // Verificar que se guardó correctamente
        const savedToken = localStorage.getItem(this.tokenKey);
        if (savedToken !== token) {
          if (!environment.production) {
            console.error('Error: El token no se guardó correctamente en localStorage');
          }
        }
      } else {
        if (!environment.production) {
          console.error('Error: Intento de guardar token inválido:', token);
        }
      }
    } catch (error) {
      if (!environment.production) {
        console.error('Error al guardar token en localStorage:', error);
      }
      throw error;
    }
  }

  /**
   * Guardar usuario
   */
  private setUser(user: User): void {
    localStorage.setItem(this.userKey, JSON.stringify(user));
  }

  /**
   * Obtener usuario almacenado
   */
  private getStoredUser(): User | null {
    const userStr = localStorage.getItem(this.userKey);
    if (userStr) {
      try {
        return JSON.parse(userStr);
      } catch {
        return null;
      }
    }
    return null;
  }

  /**
   * Eliminar token
   */
  private removeToken(): void {
    localStorage.removeItem(this.tokenKey);
  }

  /**
   * Eliminar usuario
   */
  private removeUser(): void {
    localStorage.removeItem(this.userKey);
  }
}

