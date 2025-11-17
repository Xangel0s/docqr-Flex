-- =====================================================
-- CREAR TABLA sessions (SOLO SI FALTA)
-- =====================================================
-- Este script crea únicamente la tabla sessions que Laravel necesita
-- Ejecutar en phpMyAdmin si obtienes el error:
-- "Table 'eccohgon_docqr.sessions' doesn't exist"
-- =====================================================

USE `eccohgon_docqr`;

-- Crear tabla sessions si no existe
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

-- Verificar que se creó correctamente
SHOW COLUMNS FROM `sessions`;

-- =====================================================
-- ✅ LISTO: Tabla sessions creada
-- =====================================================

