import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./features/dashboard/dashboard.component').then(m => m.DashboardComponent)
  },
  {
    path: 'upload',
    loadComponent: () => import('./features/upload/upload.component').then(m => m.UploadComponent)
  },
  {
    path: 'editor/:qrId',
    loadComponent: () => import('./features/pdf-editor/pdf-editor.component').then(m => m.PdfEditorComponent)
  },
  {
    path: 'documents',
    loadComponent: () => import('./features/documents/document-list.component').then(m => m.DocumentListComponent)
  },
  {
    path: 'settings',
    loadComponent: () => import('./features/settings/settings.component').then(m => m.SettingsComponent)
  },
      {
        path: 'help',
        loadComponent: () => import('./features/help/help.component').then(m => m.HelpComponent)
      },
      {
        path: 'compression',
        loadComponent: () => import('./features/compression/compression.component').then(m => m.CompressionComponent)
      },
      {
        path: '**',
        redirectTo: ''
      }
];

