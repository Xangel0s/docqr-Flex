-- =====================================================
-- SCRIPT COMPLETO: RECREAR BASE DE DATOS DESDE CERO
-- ⚠️ ADVERTENCIA: Este script ELIMINA y RECREA las tablas
-- Ejecutar TODO este script en phpMyAdmin
-- =====================================================

-- =====================================================
-- PASO 1: ELIMINAR TABLAS PROBLEMÁTICAS
-- =====================================================
-- Esto resuelve problemas de tablespace huérfano

DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `qr_files`;
DROP TABLE IF EXISTS `sessions`;

-- =====================================================
-- PASO 2: CREAR TABLA migrations (CONTROL DE MIGRACIONES)
-- =====================================================
-- ¿Por qué existe esta tabla?
-- Laravel usa esta tabla para saber qué migraciones ya se ejecutaron
-- Sin ella, Laravel intentaría ejecutar las migraciones de nuevo

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `migrations_migration_index` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PASO 3: CREAR TABLA qr_files (TABLA PRINCIPAL)
-- =====================================================
-- Esta es la tabla principal para los documentos con QR

CREATE TABLE `qr_files` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `qr_id` varchar(32) NOT NULL,
  `document_id` int(11) UNSIGNED DEFAULT NULL,
  `folder_name` varchar(100) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_file_deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Fecha en que se eliminó el archivo original',
  `qr_path` varchar(500) NOT NULL,
  `final_path` varchar(500) DEFAULT NULL,
  `archive_path` varchar(500) DEFAULT NULL,
  `file_size` int(10) UNSIGNED NOT NULL,
  `qr_position` json DEFAULT NULL,
  `status` enum('uploaded','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `scan_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_scanned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_files_qr_id_unique` (`qr_id`),
  KEY `qr_files_qr_id_index` (`qr_id`),
  KEY `qr_files_folder_name_index` (`folder_name`),
  KEY `qr_files_document_id_index` (`document_id`),
  KEY `qr_files_archived_status_index` (`archived`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PASO 4: CREAR TABLA sessions (PARA SESIONES DE LARAVEL)
-- =====================================================
-- Laravel necesita esta tabla para manejar sesiones de usuario

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PASO 5: REGISTRAR TODAS LAS MIGRACIONES
-- =====================================================
-- Esto le dice a Laravel que estas migraciones ya están aplicadas
-- Batch 1 = primera ejecución

-- Migración 1: Crear tabla qr_files
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2025_11_15_000000_create_qr_files_table', 1);

-- Migración 2: Adaptar tabla document (si existe)
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2025_11_15_000001_adapt_document_table_for_qr', 1);

-- Migración 3: Agregar campos archived
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2025_11_16_000000_add_archived_fields_to_qr_files', 1);

-- Migración 4: Agregar campo original_file_deleted_at
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2025_11_16_000001_add_file_deleted_at_to_qr_files', 1);

-- =====================================================
-- VERIFICACIÓN FINAL
-- =====================================================

-- Ver todas las tablas creadas
SHOW TABLES;

-- Ver todas las migraciones registradas
SELECT * FROM `migrations` ORDER BY `batch`, `migration`;

-- Ver estructura de qr_files
SHOW COLUMNS FROM `qr_files`;

-- Contar registros (debería ser 0, tabla vacía)
SELECT COUNT(*) as total_documentos FROM `qr_files`;

-- =====================================================
-- RESUMEN
-- =====================================================
-- ✅ Tabla migrations creada y registros insertados
-- ✅ Tabla qr_files creada con todos los campos
-- ✅ Tabla sessions creada para Laravel
-- ✅ Todas las migraciones registradas en batch 1
-- ✅ Sistema listo para usar

-- =====================================================
-- NOTAS IMPORTANTES
-- =====================================================
-- 1. La tabla 'document' (si existe) NO se elimina
--    Solo se eliminan las tablas problemáticas
-- 2. Los datos en 'document' se conservan
-- 3. Si necesitas agregar columnas a 'document', 
--    ejecuta los ALTER TABLE por separado
-- 4. Después de ejecutar este script, puedes usar:
--    php artisan migrate:status
--    Y debería mostrar todas las migraciones como ejecutadas

