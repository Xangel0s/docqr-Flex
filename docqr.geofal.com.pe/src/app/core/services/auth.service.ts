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
  };
}

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private apiUrl: string;
  private userKey = 'geofal_user';
  
  private currentUserSubject = new BehaviorSubject<User | null>(this.getStoredUser());
  public currentUser$ = this.currentUserSubject.asObservable();

  constructor(
    private http: HttpClient,
    private router: Router
  ) {
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
   * Login de usuario (usa sesiones con cookies)
   */
  login(username: string, password: string): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${this.apiUrl}/auth/login`, {
      username,
      password
    }, {
      withCredentials: true // Importante: enviar cookies de sesión
    }).pipe(
      tap(response => {
        // Guardar usuario de forma síncrona
        if (response && response.success && response.data && response.data.user) {
          try {
            this.setUser(response.data.user);
            this.currentUserSubject.next(response.data.user);
          } catch (error) {
            if (!environment.production) {
              console.error('Error al guardar usuario:', error);
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
    // Llamar al endpoint de logout para cerrar sesión en el servidor
    this.http.post(`${this.apiUrl}/auth/logout`, {}, {
      withCredentials: true
    }).pipe(
      catchError(() => of(null)) // Ignorar errores en logout
    ).subscribe(() => {
      this.removeUser();
      this.currentUserSubject.next(null);
      this.router.navigate(['/login']);
    });
  }

  /**
   * Verificar si el usuario está autenticado
   * Verifica si hay un usuario guardado localmente (la sesión real se verifica en checkAuth)
   */
  isAuthenticated(): boolean {
    const user = this.getStoredUser();
    return !!user;
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
    return this.http.get<{ success: boolean; data?: { user: User } }>(`${this.apiUrl}/auth/me`, {
      withCredentials: true // Importante: enviar cookies de sesión
    }).pipe(
      tap(response => {
        if (response.success && response.data && response.data.user) {
          // Actualizar usuario en caché (localStorage y BehaviorSubject)
          this.setUser(response.data.user);
          this.currentUserSubject.next(response.data.user);
        } else {
          // Solo hacer logout si el servidor dice explícitamente que no hay sesión
          this.removeUser();
          this.currentUserSubject.next(null);
        }
      }),
      catchError((error) => {
        // Solo hacer logout si es un error 401 (no autorizado)
        // Errores de red (0, timeout, etc.) no deben causar logout
        if (error.status === 401) {
          this.removeUser();
          this.currentUserSubject.next(null);
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
   * Actualizar perfil del usuario
   */
  updateProfile(name: string, email?: string): Observable<{ success: boolean; message: string; data?: { user: User } }> {
    return this.http.put<{ success: boolean; message: string; data?: { user: User } }>(
      `${this.apiUrl}/auth/profile`,
      { name, email },
      { withCredentials: true }
    ).pipe(
      tap(response => {
        if (response.success && response.data && response.data.user) {
          this.setUser(response.data.user);
          this.currentUserSubject.next(response.data.user);
        }
      })
    );
  }

  /**
   * Cambiar contraseña del usuario
   */
  updatePassword(currentPassword: string, newPassword: string, confirmPassword: string): Observable<{ success: boolean; message: string }> {
    return this.http.put<{ success: boolean; message: string }>(
      `${this.apiUrl}/auth/password`,
      { current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword },
      { withCredentials: true }
    );
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
   * Eliminar usuario
   */
  private removeUser(): void {
    localStorage.removeItem(this.userKey);
  }
}
