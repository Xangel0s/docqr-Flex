-- =====================================================
-- SQL para Base de Datos DocQR (Versión Compatible)
-- Compatible con MySQL/MariaDB sin IF NOT EXISTS
-- =====================================================

-- Base de datos: eccohgon_docqr
-- Fecha: 2025-11-15

-- =====================================================
-- 1. CREAR TABLA qr_files (NUEVA)
-- =====================================================

CREATE TABLE `qr_files` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `qr_id` varchar(32) NOT NULL,
  `document_id` int(11) UNSIGNED DEFAULT NULL,
  `folder_name` varchar(100) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `qr_path` varchar(500) NOT NULL,
  `final_path` varchar(500) DEFAULT NULL,
  `file_size` int(10) UNSIGNED NOT NULL,
  `qr_position` json DEFAULT NULL,
  `status` enum('uploaded','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
  `scan_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_scanned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_files_qr_id_unique` (`qr_id`),
  KEY `qr_files_qr_id_index` (`qr_id`),
  KEY `qr_files_folder_name_index` (`folder_name`),
  KEY `qr_files_document_id_index` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. MODIFICAR TABLA document (AGREGAR COLUMNAS)
-- =====================================================

-- Verificar si las columnas existen antes de agregarlas
-- Si alguna columna ya existe, estos comandos fallarán silenciosamente
-- Ejecuta solo los que necesites

-- Agregar qr_path
ALTER TABLE `document` 
  ADD COLUMN `qr_path` varchar(500) DEFAULT NULL AFTER `password_file`;

-- Agregar final_path
ALTER TABLE `document` 
  ADD COLUMN `final_path` varchar(500) DEFAULT NULL AFTER `qr_path`;

-- Agregar qr_position
ALTER TABLE `document` 
  ADD COLUMN `qr_position` json DEFAULT NULL AFTER `final_path`;

-- Agregar qr_status
ALTER TABLE `document` 
  ADD COLUMN `qr_status` enum('uploaded','processing','completed','failed') DEFAULT 'uploaded' AFTER `qr_position`;

-- Agregar scan_count
ALTER TABLE `document` 
  ADD COLUMN `scan_count` int(10) UNSIGNED DEFAULT 0 AFTER `qr_status`;

-- Agregar last_scanned_at
ALTER TABLE `document` 
  ADD COLUMN `last_scanned_at` timestamp NULL DEFAULT NULL AFTER `scan_count`;

-- Agregar folder_name
ALTER TABLE `document` 
  ADD COLUMN `folder_name` varchar(100) DEFAULT NULL AFTER `code`;

-- =====================================================
-- 3. CREAR TABLA sessions (PARA SESIONES DE LARAVEL)
-- =====================================================

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
-- 4. CREAR TABLA migrations (CONTROL DE MIGRACIONES)
-- =====================================================

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar registros de migraciones ejecutadas
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2025_11_15_000000_create_qr_files_table', 1),
('2025_11_15_000001_adapt_document_table_for_qr', 1)
ON DUPLICATE KEY UPDATE `batch`=`batch`;

-- =====================================================
-- FIN DEL SCRIPT
-- =====================================================

