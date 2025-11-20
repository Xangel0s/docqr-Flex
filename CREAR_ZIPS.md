# 📦 Cómo Crear los ZIPs de Producción

## ⚠️ Problema Común

Si al intentar crear los ZIPs aparece un error de "archivo en uso por otro proceso", es porque:
- El servidor de desarrollo (Laravel) está corriendo
- El servidor Angular está activo
- Algún editor tiene archivos abiertos

---

## ✅ Solución: Detener Procesos

### 1. Detener Servidores

**Frontend (Angular):**
- Ir a la terminal donde corre `npm start`
- Presionar `Ctrl + C`

**Backend (Laravel):**
- Ir a la terminal donde corre `php artisan serve`
- Presionar `Ctrl + C`

### 2. Cerrar Editores

- Cerrar VS Code, PHPStorm, o cualquier editor que tenga los archivos abiertos
- O al menos cerrar los archivos del proyecto

---

## 📦 Crear ZIPs - Opción 1: PowerShell

```powershell
# Ir al directorio del proyecto
cd C:\Users\Lenovo\Documents\docqr\docqr-Flex

# Crear ZIP del frontend (compilado)
Compress-Archive -Path "docqr-frontend\dist\docqr-frontend\*" -DestinationPath "FRONTEND-GEOFAL-PRODUCCION.zip" -Force

# Crear ZIP del backend (completo)
Compress-Archive -Path "docqr-api\*" -DestinationPath "BACKEND-GEOFAL-PRODUCCION.zip" -Force -CompressionLevel Optimal
```

---

## 📦 Crear ZIPs - Opción 2: Manualmente

### Frontend

1. Ir a: `docqr-Flex\docqr-frontend\dist\docqr-frontend\`
2. Seleccionar **TODOS** los archivos (Ctrl+A)
3. Click derecho → **Enviar a → Carpeta comprimida**
4. Renombrar a: `FRONTEND-GEOFAL-PRODUCCION.zip`
5. Mover a: `docqr-Flex\`

### Backend

1. Ir a: `docqr-Flex\docqr-api\`
2. Seleccionar **TODOS** los archivos y carpetas (Ctrl+A)
3. Click derecho → **Enviar a → Carpeta comprimida**
4. Renombrar a: `BACKEND-GEOFAL-PRODUCCION.zip`
5. Mover a: `docqr-Flex\`

---

## 📦 Crear ZIPs - Opción 3: 7-Zip (Recomendado)

Si tienes 7-Zip instalado:

### Frontend
```cmd
cd C:\Users\Lenovo\Documents\docqr\docqr-Flex
"C:\Program Files\7-Zip\7z.exe" a -tzip FRONTEND-GEOFAL-PRODUCCION.zip .\docqr-frontend\dist\docqr-frontend\*
```

### Backend
```cmd
cd C:\Users\Lenovo\Documents\docqr\docqr-Flex\docqr-api
"C:\Program Files\7-Zip\7z.exe" a -tzip ..\BACKEND-GEOFAL-PRODUCCION.zip * -xr!node_modules -xr!.git -xr!storage\logs\*.log
```

---

## ✅ Verificar ZIPs Creados

```powershell
cd C:\Users\Lenovo\Documents\docqr\docqr-Flex
Get-ChildItem -Filter "*.zip" | Select-Object Name, @{Name="Size(MB)";Expression={[math]::Round($_.Length/1MB,2)}}
```

**Tamaños esperados:**
- `FRONTEND-GEOFAL-PRODUCCION.zip`: ~2-5 MB
- `BACKEND-GEOFAL-PRODUCCION.zip`: ~30-50 MB (depende de vendor/)

---

## 📋 Contenido de los ZIPs

### FRONTEND-GEOFAL-PRODUCCION.zip debe contener:
```
├── index.html
├── main.*.js
├── polyfills.*.js
├── runtime.*.js
├── styles.*.css
├── assets/
│   ├── images/
│   ├── videos/
│   └── ...
├── 277.*.js (chunks lazy loading)
├── 67.*.js
├── ...
└── .htaccess (copiar manualmente si no está)
```

### BACKEND-GEOFAL-PRODUCCION.zip debe contener:
```
├── app/
├── bootstrap/
├── config/
├── database/
├── public/
│   ├── index.php
│   └── .htaccess
├── resources/
├── routes/
├── storage/
├── vendor/
├── .env.production
├── .user.ini.example
├── .htaccess.terminal
├── php81
├── setup-cpanel.sh
├── artisan
├── composer.json
└── ...
```

---

## 🚀 Después de Crear los ZIPs

1. Subir ambos ZIPs al servidor cPanel
2. Extraer en las ubicaciones correctas:
   - Frontend → `public_html/docqr/`
   - Backend → `public_html/docqr-api/`
3. Seguir: `INSTRUCCIONES_DESPLIEGUE_RAPIDO.md`

---

## 💡 Consejo

**Para evitar problemas:**
1. Cierra TODOS los programas que usen los archivos
2. Detén servidores de desarrollo
3. Espera 5-10 segundos
4. Intenta crear los ZIPs de nuevo

---

**✅ Una vez creados los ZIPs, estarás listo para desplegar en cPanel!**

