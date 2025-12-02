# 📁 Base de Datos - Organización

Esta carpeta contiene todos los archivos relacionados con la base de datos del proyecto DocQR.

## 📂 **Estructura de Carpetas**

```
database/
├── sql/              # 📄 Archivos SQL (scripts de base de datos)
│   ├── CREAR_BASE_DATOS_COMPLETA.sql    ⭐ RECOMENDADO
│   ├── CREAR_TABLA_SESSIONS.sql         ⭐ Para errores de sesiones
│   └── ... (otros archivos SQL)
│
├── scripts/          # 🔧 Scripts PHP de utilidad
│   ├── crear_qr_files.php
│   ├── registrar_migraciones.php
│   └── ... (otros scripts PHP)
│
├── docs/             # 📚 Documentación
│   ├── README_SQL.md                    ⭐ Guía de archivos SQL
│   ├── EXPLICACION_MIGRACIONES.md
│   └── ... (otros documentos)
│
└── migrations/       # 🗄️ Migraciones de Laravel
    ├── 2025_11_15_000000_create_qr_files_table.php
    └── ... (otras migraciones)
```

---

## 🚀 **Inicio Rápido**

### **Crear Base de Datos desde Cero**
1. Abre phpMyAdmin
2. Ve a la pestaña "SQL"
3. Copia y pega el contenido de: **`sql/CREAR_BASE_DATOS_COMPLETA.sql`**
4. Ejecuta el script

### **Solucionar Error de Sesiones**
Si obtienes el error: `Table 'sessions' doesn't exist`
1. Abre phpMyAdmin
2. Ve a la pestaña "SQL"
3. Copia y pega el contenido de: **`sql/CREAR_TABLA_SESSIONS.sql`**
4. Ejecuta el script

---

## 📚 **Documentación**

- **`docs/README_SQL.md`** - Guía completa de todos los archivos SQL (cuáles usar y cuáles no)
- **`docs/EXPLICACION_MIGRACIONES.md`** - Explicación sobre las migraciones
- **`docs/INSTRUCCIONES_MIGRACIONES.md`** - Instrucciones para aplicar migraciones

---

## 🔧 **Scripts PHP**

Los scripts en `scripts/` son herramientas de utilidad para:
- Crear tablas manualmente
- Registrar migraciones
- Verificar estructura de base de datos
- Solucionar problemas específicos

**Nota:** Estos scripts son para uso administrativo/debugging. En producción, usa las migraciones de Laravel.

---

## 📝 **Notas Importantes**

1. **Siempre hacer backup** antes de ejecutar scripts SQL
2. **`sql/CREAR_BASE_DATOS_COMPLETA.sql`** es el script más completo y actualizado
3. **`sql/CREAR_TABLA_SESSIONS.sql`** es solo para solucionar el error de sesiones
4. Las migraciones de Laravel están en `migrations/` y se ejecutan con `php artisan migrate`

---

## 🆘 **Soporte**

Si tienes problemas:
1. Revisa `docs/README_SQL.md` para ver qué archivo SQL usar
2. Verifica los logs de Laravel en `storage/logs/laravel.log`
3. Consulta la documentación en `docs/`

