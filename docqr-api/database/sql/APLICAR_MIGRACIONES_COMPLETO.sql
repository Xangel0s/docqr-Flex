-- =====================================================
-- SCRIPT COMPLETO PARA APLICAR MIGRACIONES
-- Ejecutar TODO este script en phpMyAdmin
-- =====================================================

-- PASO 1: Recrear tabla migrations (si tiene problemas de tablespace)
DROP TABLE IF EXISTS `migrations`;

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `migrations_migration_index` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PASO 2: Obtener el siguiente batch (si hay migraciones existentes)
-- Si es la primera vez, el batch será 1
SET @next_batch = COALESCE((SELECT MAX(batch) FROM `migrations`), 0) + 1;

-- Si no hay migraciones, usar batch 1
SET @next_batch = IF(@next_batch = 0, 1, @next_batch);

-- PASO 3: Registrar migraciones de qr_files
-- Migración 1: Crear tabla qr_files
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_15_000000_create_qr_files_table', @next_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` 
    WHERE `migration` = '2025_11_15_000000_create_qr_files_table'
);

-- Migración 2: Agregar campos archived
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_16_000000_add_archived_fields_to_qr_files', @next_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` 
    WHERE `migration` = '2025_11_16_000000_add_archived_fields_to_qr_files'
);

-- Migración 3: Agregar campo original_file_deleted_at
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2025_11_16_000001_add_file_deleted_at_to_qr_files', @next_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations` 
    WHERE `migration` = '2025_11_16_000001_add_file_deleted_at_to_qr_files'
);

-- =====================================================
-- VERIFICACIÓN
-- =====================================================

-- Ver todas las migraciones registradas
SELECT * FROM `migrations` ORDER BY `batch`, `migration`;

-- Ver solo las migraciones de qr_files
SELECT * FROM `migrations` 
WHERE `migration` LIKE '%qr_files%' 
ORDER BY `batch`, `migration`;

-- Verificar que la tabla qr_files existe
SHOW TABLES LIKE 'qr_files';

-- Ver estructura de qr_files
SHOW COLUMNS FROM `qr_files`;

