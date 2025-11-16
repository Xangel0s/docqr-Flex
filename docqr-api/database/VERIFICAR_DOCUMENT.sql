-- =====================================================
-- SCRIPT PARA VERIFICAR Y COMPLETAR TABLA document
-- =====================================================

-- 1. Verificar si la tabla document existe
SHOW TABLES LIKE 'document';

-- 2. Ver estructura actual de document (si existe)
DESCRIBE document;

-- 3. Ver todas las columnas de document
SHOW COLUMNS FROM document;

-- =====================================================
-- AGREGAR COLUMNAS FALTANTES A document
-- (Solo ejecuta si la tabla document existe)
-- =====================================================

-- Verificar y agregar columnas si no existen
-- Si alguna columna ya existe, verás un error (es normal, ignóralo)

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
-- VERIFICAR RESULTADO FINAL
-- =====================================================

-- Ver todas las tablas
SHOW TABLES;

-- Ver estructura completa de document
DESCRIBE document;

