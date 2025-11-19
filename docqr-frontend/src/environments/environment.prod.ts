// Configuración de entorno para producción
export const environment = {
  production: true,
  // URL absoluta del backend (subdominio diferente)
  // NOTA: Sin /api al final - se agrega automáticamente en docqr.service.ts
  apiUrl: 'https://docqr-api.geofal.com.pe',
  baseUrl: ''
};

