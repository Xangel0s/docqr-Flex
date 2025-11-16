-- =====================================================
-- SQL PARA COMPLETAR LA CONFIGURACIÓN
-- Ejecuta este SQL después de las migraciones iniciales
-- =====================================================

-- =====================================================
-- 1. CREAR TABLA sessions (PARA SESIONES DE LARAVEL)
-- Esto resuelve el error "Table 'sessions' doesn't exist"
-- =====================================================

CREATE TABLE IF NOT EXISTS `sessions` (
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
-- 2. MODIFICAR TABLA document (AGREGAR COLUMNAS NUEVAS)
-- Agrega las columnas necesarias para el sistema QR
-- =====================================================

-- Agregar qr_path (si no existe)
ALTER TABLE `document` 
  ADD COLUMN `qr_path` varchar(500) DEFAULT NULL AFTER `password_file`;

-- Agregar final_path (si no existe)
ALTER TABLE `document` 
  ADD COLUMN `final_path` varchar(500) DEFAULT NULL AFTER `qr_path`;

-- Agregar qr_position (si no existe)
ALTER TABLE `document` 
  ADD COLUMN `qr_position` json DEFAULT NULL AFTER `final_path`;

-- Agregar qr_status (si no existe)
ALTER TABLE `document` 
  ADD COLUMN `qr_status` enum('uploaded','processing','completed','failed') DEFAULT 'uploaded' AFTER `qr_position`;

-- Agregar scan_count (si no existe)
ALTER TABLE `document` 
  ADD COLUMN `scan_count` int(10) UNSIGNED DEFAULT 0 AFTER `qr_status`;

-- Agregar last_scanned_at (si no existe)
ALTER TABLE `document` 
  ADD COLUMN `last_scanned_at` timestamp NULL DEFAULT NULL AFTER `scan_count`;

-- Agregar folder_name (si no existe)
ALTER TABLE `document` 
  ADD COLUMN `folder_name` varchar(100) DEFAULT NULL AFTER `code`;

-- =====================================================
-- FIN DEL SCRIPT
-- =====================================================
-- NOTA: Si alguna columna ya existe, verás un error.
-- Eso es normal, simplemente ignóralo y continúa.

