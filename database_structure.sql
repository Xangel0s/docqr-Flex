-- =====================================================
-- Estructura de Base de Datos para DocQR - Geofal
-- =====================================================
-- Para migrar desde base de datos local a producción
-- =====================================================

-- Crear base de datos
CREATE DATABASE IF NOT EXISTS `geofal_docqr` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `geofal_docqr`;

-- =====================================================
-- Tabla: users (Usuarios del sistema)
-- =====================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuario admin por defecto (password: admin123)

INSERT INTO `users` (`id`, `name`, `email`, `password`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Administrador', 'admin@docqr.com', '$2y$12$LDmkQVjPzPE/8Z3WqXaJU.kC0VRw9oN4nMJxGXh1ZxY/4a9r0Pxeu', 1, NOW(), NOW());

-- =====================================================
-- Tabla: qr_files (Documentos con códigos QR)
-- =====================================================

CREATE TABLE IF NOT EXISTS `qr_files` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `qr_id` varchar(20) NOT NULL COMMENT 'ID único del QR',
  `folder_name` varchar(255) NOT NULL COMMENT 'Nombre de la carpeta/código',
  `file_path` varchar(500) DEFAULT NULL COMMENT 'Ruta del PDF original',
  `original_filename` varchar(255) DEFAULT NULL COMMENT 'Nombre original del archivo',
  `file_size` bigint(20) DEFAULT NULL COMMENT 'Tamaño del archivo en bytes',
  `qr_path` varchar(500) DEFAULT NULL COMMENT 'Ruta del código QR generado',
  `final_path` varchar(500) DEFAULT NULL COMMENT 'Ruta del PDF con QR integrado',
  `qr_position` text DEFAULT NULL COMMENT 'Posición del QR en el PDF (JSON)',
  `status` enum('pending','uploaded','completed') NOT NULL DEFAULT 'pending',
  `scan_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Número de escaneos del QR',
  `last_scanned_at` timestamp NULL DEFAULT NULL COMMENT 'Última vez que se escaneó',
  `notes` text DEFAULT NULL COMMENT 'Notas adicionales',
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_reason` text DEFAULT NULL,
  `file_deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Fecha de eliminación del archivo físico',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_files_qr_id_unique` (`qr_id`),
  KEY `qr_files_folder_name_index` (`folder_name`),
  KEY `qr_files_status_index` (`status`),
  KEY `qr_files_created_at_index` (`created_at`),
  KEY `qr_files_scan_count_index` (`scan_count`),
  KEY `qr_files_last_scanned_at_index` (`last_scanned_at`),
  KEY `qr_files_deleted_at_index` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: cache (Cache del sistema)
-- =====================================================

CREATE TABLE IF NOT EXISTS `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: migrations (Control de migraciones)
-- =====================================================

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrar migraciones ejecutadas
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2025_11_15_000000_create_qr_files_table', 1),
('2025_11_15_000001_adapt_document_table_for_qr', 1),
('2025_11_16_000000_add_archived_fields_to_qr_files', 1),
('2025_11_16_000001_add_file_deleted_at_to_qr_files', 1),
('2025_11_17_102242_create_users_table', 1),
('2025_11_17_105000_add_indexes_for_performance', 1),
('2025_11_17_113222_make_qr_path_nullable_in_qr_files_table', 1),
('2025_11_17_125348_create_cache_table', 1),
('2025_11_19_000000_make_file_fields_nullable_in_qr_files', 1),
('2025_01_20_000000_add_indexes_to_qr_files_table', 1);

-- =====================================================
-- Notas sobre la migración:
-- =====================================================
-- 1. Esta estructura es la mínima necesaria
-- 2. Laravel creará automáticamente tablas adicionales si es necesario
-- 3. Los archivos físicos (PDFs, QRs) se almacenan en storage/app/
-- 4. El usuario admin tiene password: admin123 (cambiar después del primer login)
-- 5. Ajustar el nombre de la base de datos según tu servidor:
--    - Local: docqr_database
--    - Producción: geofal_docqr

-- =====================================================
-- Para importar en producción:
-- =====================================================
-- mysql -u geofal_docqr_user -p geofal_docqr < database_structure.sql

-- =====================================================
-- Para exportar datos desde local:
-- =====================================================
-- mysqldump -u root -p grersced_docqr > backup_local.sql
-- Luego editar el SQL para cambiar el nombre de BD si es necesario

-- =====================================================
-- Fin del script
-- =====================================================

