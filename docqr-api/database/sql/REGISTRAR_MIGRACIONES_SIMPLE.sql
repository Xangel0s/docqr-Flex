-- =====================================================
-- Script SIMPLE para registrar migraciones
-- Copia y pega cada bloque en phpMyAdmin
-- =====================================================

-- MIGRACIÓN 1: Crear tabla qr_files
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_15_000000_create_qr_files_table', COALESCE(MAX(batch), 0) + 1 
FROM `migrations`
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` 
    WHERE `migration` = '2025_11_15_000000_create_qr_files_table'
);

-- MIGRACIÓN 2: Agregar campos archived
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_16_000000_add_archived_fields_to_qr_files', COALESCE(MAX(batch), 0) + 1 
FROM `migrations`
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` 
    WHERE `migration` = '2025_11_16_000000_add_archived_fields_to_qr_files'
);

-- MIGRACIÓN 3: Agregar campo original_file_deleted_at
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_16_000001_add_file_deleted_at_to_qr_files', COALESCE(MAX(batch), 0) + 1 
FROM `migrations`
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` 
    WHERE `migration` = '2025_11_16_000001_add_file_deleted_at_to_qr_files'
);

