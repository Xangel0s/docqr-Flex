import { Routes } from '@angular/router';
import { AuthGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () => import('./features/login/login.component').then(m => m.LoginComponent)
  },
  {
    path: '',
    loadComponent: () => import('./features/dashboard/dashboard.component').then(m => m.DashboardComponent),
    canActivate: [AuthGuard]
  },
  {
    path: 'upload',
    loadComponent: () => import('./features/upload/upload.component').then(m => m.UploadComponent),
    canActivate: [AuthGuard]
  },
  {
    path: 'editor/:qrId',
    loadComponent: () => import('./features/pdf-editor/pdf-editor.component').then(m => m.PdfEditorComponent),
    canActivate: [AuthGuard]
  },
  {
    path: 'documents',
    loadComponent: () => import('./features/documents/document-list.component').then(m => m.DocumentListComponent),
    canActivate: [AuthGuard]
  },
  {
    path: 'settings',
    loadComponent: () => import('./features/settings/settings.component').then(m => m.SettingsComponent),
    canActivate: [AuthGuard]
  },
  {
    path: 'help',
    loadComponent: () => import('./features/help/help.component').then(m => m.HelpComponent),
    canActivate: [AuthGuard]
  },
  {
    path: 'compression',
    loadComponent: () => import('./features/compression/compression.component').then(m => m.CompressionComponent),
    canActivate: [AuthGuard]
  },
  {
    path: '**',
    redirectTo: ''
  }
];

