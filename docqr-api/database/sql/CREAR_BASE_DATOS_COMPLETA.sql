-- =====================================================
-- SCRIPT COMPLETO: CREAR BASE DE DATOS DESDE CERO
-- Ejecutar TODO este script en phpMyAdmin
-- =====================================================

-- =====================================================
-- PASO 1: CREAR BASE DE DATOS (si no existe)
-- =====================================================
CREATE DATABASE IF NOT EXISTS `eccohgon_docqr` 
DEFAULT CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `eccohgon_docqr`;

-- =====================================================
-- PASO 2: CREAR TABLA migrations (CONTROL DE MIGRACIONES)
-- =====================================================
-- Esta tabla es necesaria para que Laravel sepa qué migraciones ya se ejecutaron

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
-- Incluye todos los campos de todas las migraciones

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
-- PASO 5: CREAR TABLA document (OPCIONAL - Para compatibilidad)
-- =====================================================
-- Esta tabla es opcional, solo créala si necesitas compatibilidad con el sistema antiguo
-- Si no la necesitas, puedes comentar o eliminar esta sección

CREATE TABLE IF NOT EXISTS `document` (
  `document_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_type_id` int(11) UNSIGNED DEFAULT NULL,
  `prefix_code` varchar(50) DEFAULT NULL,
  `code` varchar(100) DEFAULT NULL,
  `folder_name` varchar(100) DEFAULT NULL COMMENT 'Nombre de carpeta para sistema QR',
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) UNSIGNED DEFAULT NULL,
  `documenting_user_id` int(11) UNSIGNED DEFAULT NULL,
  `status_id` int(11) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `audit_user_id` int(11) UNSIGNED DEFAULT NULL,
  `password_file` varchar(255) DEFAULT NULL,
  `qr_path` varchar(500) DEFAULT NULL COMMENT 'Ruta de la imagen QR',
  `final_path` varchar(500) DEFAULT NULL COMMENT 'Ruta del PDF final con QR',
  `qr_position` json DEFAULT NULL COMMENT 'Posición del QR: {x, y, width, height}',
  `qr_status` enum('uploaded','processing','completed','failed') DEFAULT 'uploaded' COMMENT 'Estado del procesamiento QR',
  `scan_count` int(10) UNSIGNED DEFAULT 0 COMMENT 'Contador de escaneos',
  `last_scanned_at` timestamp NULL DEFAULT NULL COMMENT 'Fecha del último escaneo',
  `is_file_name_encript` int(11) DEFAULT 0,
  `creation_date` timestamp NULL DEFAULT NULL,
  `update_date` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `document_code_index` (`code`),
  KEY `document_folder_name_index` (`folder_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PASO 6: REGISTRAR TODAS LAS MIGRACIONES
-- =====================================================
-- Esto le dice a Laravel que estas migraciones ya están aplicadas
-- Batch 1 = primera ejecución

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2025_11_15_000000_create_qr_files_table', 1),
('2025_11_15_000001_adapt_document_table_for_qr', 1),
('2025_11_16_000000_add_archived_fields_to_qr_files', 1),
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

-- Ver estructura de sessions
SHOW COLUMNS FROM `sessions`;

-- Ver estructura de document (si se creó)
SHOW COLUMNS FROM `document`;

-- Contar registros (deberían ser 0, tablas vacías)
SELECT 
    (SELECT COUNT(*) FROM `qr_files`) as total_qr_files,
    (SELECT COUNT(*) FROM `document`) as total_documents,
    (SELECT COUNT(*) FROM `migrations`) as total_migrations;

-- =====================================================
-- RESUMEN
-- =====================================================
-- ✅ Base de datos eccohgon_docqr creada
-- ✅ Tabla migrations creada y registros insertados
-- ✅ Tabla qr_files creada con todos los campos
-- ✅ Tabla sessions creada para Laravel
-- ✅ Tabla document creada (opcional, para compatibilidad)
-- ✅ Todas las migraciones registradas en batch 1
-- ✅ Sistema listo para usar

-- =====================================================
-- NOTAS IMPORTANTES
-- =====================================================
-- 1. La tabla 'document' es OPCIONAL
--    Si no la necesitas, puedes eliminar esa sección
-- 2. Después de ejecutar este script, puedes usar:
--    php artisan migrate:status
--    Y debería mostrar todas las migraciones como ejecutadas
-- 3. Todas las tablas están vacías (sin datos)
--    Listas para empezar a usar el sistema

