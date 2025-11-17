-- =====================================================
-- ALTERNATIVA: Usar INSERT IGNORE (más simple)
-- Si el método anterior no funciona, usa este
-- =====================================================

-- Obtener el siguiente batch
SET @next_batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM `migrations`);

-- Migración 1
INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES ('2025_11_15_000000_create_qr_files_table', @next_batch);

-- Migración 2
INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES ('2025_11_16_000000_add_archived_fields_to_qr_files', @next_batch);

-- Migración 3
INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES ('2025_11_16_000001_add_file_deleted_at_to_qr_files', @next_batch);

