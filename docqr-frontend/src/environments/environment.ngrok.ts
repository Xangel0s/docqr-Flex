// Configuración de entorno para pruebas con ngrok
// Uso: ng serve --configuration=ngrok
// 
// IMPORTANTE: Actualiza las URLs con tus URLs de ngrok:
// 1. Inicia ngrok para backend: ngrok http 8000
// 2. Inicia ngrok para frontend: ngrok http 4200
// 3. Copia las URLs públicas y actualiza este archivo

export const environment = {
  production: false,
  // URL pública de ngrok para el backend (ej: https://abc123.ngrok.io)
  apiUrl: 'https://TU_URL_NGROK_BACKEND.ngrok.io/api',
  // URL pública de ngrok para el frontend (ej: https://xyz789.ngrok.io)
  baseUrl: 'https://TU_URL_NGROK_FRONTEND.ngrok.io'
};

