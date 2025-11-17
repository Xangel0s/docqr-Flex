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
    token: string;
  };
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
    if (environment.apiUrl.startsWith('http://') && window.location.protocol === 'https:') {
      // Usar URL relativa para evitar Mixed Content
      this.apiUrl = '/api';
    } else if (environment.apiUrl.startsWith('http://localhost') && window.location.protocol === 'https:') {
      // Si estamos en HTTPS pero apiUrl apunta a localhost HTTP, usar URL relativa
      this.apiUrl = '/api';
    } else {
      // Usar la URL configurada normalmente
      this.apiUrl = environment.apiUrl;
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
        if (response.success && response.data) {
          this.setToken(response.data.token);
          this.setUser(response.data.user);
          this.currentUserSubject.next(response.data.user);
        }
      }),
      catchError(error => {
        console.error('Error en login:', error);
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
   * Guardar token
   */
  private setToken(token: string): void {
    localStorage.setItem(this.tokenKey, token);
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

