-- =====================================================
-- MIGRACIÓN: DE TOKENS (SANCTUM) A SESIONES
-- =====================================================
-- Este script prepara la base de datos para usar sesiones
-- en lugar de tokens de autenticación (Sanctum)
-- =====================================================
-- INSTRUCCIONES:
-- 1. Ejecutar en phpMyAdmin
-- 2. Seleccionar la base de datos correcta
-- 3. Ejecutar todo el script
-- =====================================================

-- =====================================================
-- PASO 1: VERIFICAR/CREAR TABLA sessions
-- =====================================================
-- Laravel necesita esta tabla para manejar sesiones de usuario
-- Si ya existe, no se modificará

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
-- PASO 2: VERIFICAR/CREAR TABLA cache
-- =====================================================
-- Necesaria para cache en base de datos (CACHE_STORE=database)

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
-- PASO 3: VERIFICAR TABLA users
-- =====================================================
-- Asegurar que la tabla users existe con los campos necesarios

CREATE TABLE IF NOT EXISTS `users` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` enum('admin') NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PASO 4: LIMPIAR TABLA personal_access_tokens (OPCIONAL)
-- =====================================================
-- Esta tabla ya no se necesita porque no usamos Sanctum
-- Opción 1: Eliminar todos los tokens (recomendado)
-- Opción 2: Eliminar la tabla completa (si estás seguro)

-- Opción 1: Limpiar tokens (descomentar si quieres eliminar los tokens)
-- DELETE FROM `personal_access_tokens`;

-- Opción 2: Eliminar tabla completa (descomentar si quieres eliminar la tabla)
-- DROP TABLE IF EXISTS `personal_access_tokens`;

-- NOTA: Si prefieres mantener la tabla por si acaso, no ejecutes las opciones anteriores
-- La tabla no causará problemas si está vacía

-- =====================================================
-- PASO 5: LIMPIAR SESIONES ANTIGUAS (OPCIONAL)
-- =====================================================
-- Eliminar sesiones con más de 2 horas de inactividad
-- Esto ayuda a mantener la tabla sessions limpia

DELETE FROM `sessions` 
WHERE `last_activity` < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 2 HOUR));

-- =====================================================
-- VERIFICACIÓN FINAL
-- =====================================================

-- Verificar que las tablas existen
SELECT 
    'sessions' as tabla,
    CASE WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sessions') 
         THEN '✅ Existe' 
         ELSE '❌ No existe' 
    END as estado
UNION ALL
SELECT 
    'cache' as tabla,
    CASE WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cache') 
         THEN '✅ Existe' 
         ELSE '❌ No existe' 
    END as estado
UNION ALL
SELECT 
    'users' as tabla,
    CASE WHEN EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users') 
         THEN '✅ Existe' 
         ELSE '❌ No existe' 
    END as estado;

-- Ver estructura de sessions
SHOW COLUMNS FROM `sessions`;

-- Ver estructura de cache
SHOW COLUMNS FROM `cache`;

-- Ver estructura de users
SHOW COLUMNS FROM `users`;

-- Contar registros
SELECT 
    (SELECT COUNT(*) FROM `sessions`) as total_sessions,
    (SELECT COUNT(*) FROM `cache`) as total_cache,
    (SELECT COUNT(*) FROM `users`) as total_users;

-- =====================================================
-- RESUMEN
-- =====================================================
-- ✅ Tabla sessions creada/verificada
-- ✅ Tabla cache creada/verificada
-- ✅ Tabla users verificada
-- ✅ Sistema listo para usar sesiones en lugar de tokens
-- 
-- IMPORTANTE: 
-- - Configurar SESSION_DRIVER=database en .env
-- - Configurar CACHE_STORE=database en .env
-- - Las sesiones se almacenarán en la tabla sessions
-- - Los tokens de Sanctum ya no se usarán
-- =====================================================

