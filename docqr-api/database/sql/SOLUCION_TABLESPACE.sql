-- =====================================================
-- SOLUCIÓN PARA TABLESPACE HUÉRFANO
-- Ejecutar este script en phpMyAdmin o MySQL
-- =====================================================

-- PASO 1: Crear la tabla temporalmente para poder descartar el tablespace
CREATE TABLE IF NOT EXISTS `qr_files_temp` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- PASO 2: Eliminar cualquier tabla qr_files que pueda existir
DROP TABLE IF EXISTS `qr_files`;

-- PASO 3: Esperar un momento (ejecutar manualmente si es necesario)
-- En MySQL puedes usar: SELECT SLEEP(1);

-- PASO 4: Crear la tabla qr_files completa
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

-- PASO 5: Eliminar la tabla temporal
DROP TABLE IF EXISTS `qr_files_temp`;

-- PASO 6: Registrar las migraciones
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_15_000000_create_qr_files_table', COALESCE(MAX(batch), 0) + 1 FROM `migrations`
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2025_11_15_000000_create_qr_files_table');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_16_000000_add_archived_fields_to_qr_files', COALESCE(MAX(batch), 0) + 1 FROM `migrations`
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2025_11_16_000000_add_archived_fields_to_qr_files');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_16_000001_add_file_deleted_at_to_qr_files', COALESCE(MAX(batch), 0) + 1 FROM `migrations`
WHERE NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2025_11_16_000001_add_file_deleted_at_to_qr_files');

-- =====================================================
-- VERIFICACIÓN
-- =====================================================
-- Ejecuta esto para verificar:
-- SHOW COLUMNS FROM qr_files;
-- SELECT COUNT(*) FROM qr_files;

