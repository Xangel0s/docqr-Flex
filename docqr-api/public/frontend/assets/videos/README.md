# 📹 Videos Tutoriales

## 📁 Ubicación
Coloca tus videos tutoriales en esta carpeta: `src/assets/videos/`

## 📋 Formato Recomendado
- **Formato:** MP4 (H.264)
- **Resolución:** 1920x1080 (Full HD) o 1280x720 (HD)
- **Duración:** 2-5 minutos recomendado
- **Tamaño:** < 50 MB por video (para carga rápida)

## 🎬 Video: Tutorial de Subida de Documentos

**Nombre del archivo:** `tutorial-subida.mp4`

**Contenido:**
- Cómo subir un PDF
- Cómo seleccionar tipo de documento (CE, IN, SU)
- Cómo ingresar el código
- Cómo posicionar el QR en el editor
- Cómo guardar y descargar

## 🔗 Alternativas

### Opción 1: Video Local (Recomendado)
Coloca el video en `src/assets/videos/tutorial-subida.mp4`
El componente lo cargará automáticamente.

### Opción 2: URL Externa
Modifica `help.component.ts`:
```typescript
videoUrl: string = 'https://tudominio.com/videos/tutorial-subida.mp4';
```

### Opción 3: YouTube/Vimeo
Si quieres usar YouTube o Vimeo, modifica el HTML para usar iframe:
```html
<iframe 
  src="https://www.youtube.com/embed/VIDEO_ID"
  frameborder="0"
  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
  allowfullscreen>
</iframe>
```

## ✅ Verificación
Después de agregar el video, verifica:
1. El archivo existe en `src/assets/videos/`
2. El nombre coincide con `videoUrl` en el componente
3. El formato es compatible (MP4 recomendado)
4. El tamaño es razonable (< 50 MB)

