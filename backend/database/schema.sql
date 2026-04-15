-- CRM Luxom - Database Schema
-- Run this on your Hostinger MySQL database

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- USUARIOS
-- ============================================
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL,
    `usuario` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `rol` ENUM('admin', 'vendedor', 'supervisor') NOT NULL DEFAULT 'vendedor',
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `crm_activo` TINYINT(1) NOT NULL DEFAULT 1,
    `crm_online` TINYINT(1) NOT NULL DEFAULT 0,
    `max_chats` INT NOT NULL DEFAULT 20,
    `ultimo_login` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INSTANCIAS (Evolution API)
-- ============================================
CREATE TABLE IF NOT EXISTS `instancias` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL,
    `api_url` VARCHAR(500) NOT NULL,
    `api_key` VARCHAR(255) NOT NULL,
    `activa` TINYINT(1) NOT NULL DEFAULT 1,
    `estado_conexion` VARCHAR(50) DEFAULT 'disconnected',
    `telefono` VARCHAR(20) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CONTACTOS
-- ============================================
CREATE TABLE IF NOT EXISTS `contactos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(200) DEFAULT '',
    `telefono` VARCHAR(30) NOT NULL,
    `email` VARCHAR(200) DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `instancia_id` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_telefono` (`telefono`),
    KEY `idx_instancia` (`instancia_id`),
    CONSTRAINT `fk_contacto_instancia` FOREIGN KEY (`instancia_id`) REFERENCES `instancias`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- MENSAJES
-- ============================================
CREATE TABLE IF NOT EXISTS `mensajes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `telefono` VARCHAR(30) NOT NULL,
    `mensaje` TEXT DEFAULT NULL,
    `tipo` ENUM('enviado', 'recibido') NOT NULL,
    `media_url` VARCHAR(500) DEFAULT NULL,
    `media_type` VARCHAR(50) DEFAULT NULL,
    `instancia_id` INT DEFAULT NULL,
    `message_id` VARCHAR(100) DEFAULT NULL,
    `status` VARCHAR(20) DEFAULT 'sent',
    `fecha` DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_telefono` (`telefono`),
    KEY `idx_fecha` (`fecha`),
    KEY `idx_instancia` (`instancia_id`),
    KEY `idx_message_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CRM LEADS
-- ============================================
CREATE TABLE IF NOT EXISTS `crm_leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `contacto_id` INT DEFAULT NULL,
    `telefono` VARCHAR(30) NOT NULL,
    `nombre` VARCHAR(200) DEFAULT '',
    `estado` ENUM('nuevo', 'asignado', 'en_proceso', 'cerrado_positivo', 'cerrado_negativo') NOT NULL DEFAULT 'nuevo',
    `usuario_asignado_id` INT DEFAULT NULL,
    `instancia_id` INT DEFAULT NULL,
    `origen` VARCHAR(100) DEFAULT 'whatsapp',
    `notas` TEXT DEFAULT NULL,
    `prioridad` TINYINT NOT NULL DEFAULT 0,
    `fecha_asignacion` DATETIME DEFAULT NULL,
    `fecha_cierre` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_estado` (`estado`),
    KEY `idx_usuario` (`usuario_asignado_id`),
    KEY `idx_telefono` (`telefono`),
    CONSTRAINT `fk_lead_usuario` FOREIGN KEY (`usuario_asignado_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_lead_contacto` FOREIGN KEY (`contacto_id`) REFERENCES `contactos`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CRM CONFIG
-- ============================================
CREATE TABLE IF NOT EXISTS `crm_config` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `modo_distribucion` ENUM('round_robin', 'least_chats') NOT NULL DEFAULT 'round_robin',
    `auto_asignar` TINYINT(1) NOT NULL DEFAULT 1,
    `max_chats_por_usuario` INT NOT NULL DEFAULT 20,
    `ultimo_asignado_id` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `crm_config` (`id`, `modo_distribucion`) VALUES (1, 'round_robin');

-- ============================================
-- CRM ETIQUETAS
-- ============================================
CREATE TABLE IF NOT EXISTS `crm_etiquetas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL,
    `color` VARCHAR(7) NOT NULL DEFAULT '#6c757d',
    `orden` INT NOT NULL DEFAULT 0,
    `activa` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- CAMPAÑAS
-- ============================================
CREATE TABLE IF NOT EXISTS `campanas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(200) NOT NULL,
    `mensaje` TEXT NOT NULL,
    `media_url` VARCHAR(500) DEFAULT NULL,
    `media_type` VARCHAR(50) DEFAULT NULL,
    `estado` ENUM('borrador', 'programada', 'activa', 'pausada', 'completada', 'cancelada') NOT NULL DEFAULT 'borrador',
    `instancia_id` INT DEFAULT NULL,
    `total_contactos` INT NOT NULL DEFAULT 0,
    `enviados` INT NOT NULL DEFAULT 0,
    `fallidos` INT NOT NULL DEFAULT 0,
    `intervalo_min` INT NOT NULL DEFAULT 30,
    `intervalo_max` INT NOT NULL DEFAULT 60,
    `fecha_programada` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_estado` (`estado`),
    CONSTRAINT `fk_campana_instancia` FOREIGN KEY (`instancia_id`) REFERENCES `instancias`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- AUTO RESPUESTAS
-- ============================================
CREATE TABLE IF NOT EXISTS `auto_respuestas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `trigger_text` VARCHAR(200) NOT NULL,
    `respuesta` TEXT NOT NULL,
    `tipo_match` ENUM('exacto', 'contiene', 'regex') NOT NULL DEFAULT 'contiene',
    `activa` TINYINT(1) NOT NULL DEFAULT 1,
    `instancia_id` INT DEFAULT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- ADMIN USER (password: admin123 - CAMBIAR!)
-- ============================================
INSERT IGNORE INTO `usuarios` (`id`, `nombre`, `usuario`, `password`, `rol`) 
VALUES (1, 'Admin', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

SET FOREIGN_KEY_CHECKS = 1;
