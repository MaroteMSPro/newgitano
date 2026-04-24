/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `campana_envios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `campana_envios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campana_id` int(11) NOT NULL,
  `contacto_id` int(11) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `estado` enum('pendiente','enviado','fallido') DEFAULT 'pendiente',
  `error_mensaje` varchar(500) DEFAULT NULL,
  `enviado_at` datetime DEFAULT NULL,
  `tanda_numero` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_campana_contacto` (`campana_id`,`contacto_id`),
  KEY `idx_envios_estado` (`estado`),
  KEY `idx_envios_campana` (`campana_id`),
  CONSTRAINT `campana_envios_ibfk_1` FOREIGN KEY (`campana_id`) REFERENCES `campanas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21074 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campanas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `campanas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `mensaje` text NOT NULL,
  `imagen_url` varchar(500) DEFAULT NULL,
  `imagen_caption` text DEFAULT NULL,
  `delay_min` int(11) DEFAULT 5,
  `delay_max` int(11) DEFAULT 15,
  `estado` varchar(20) DEFAULT 'borrador',
  `programada_para` datetime DEFAULT NULL,
  `total_contactos` int(11) DEFAULT 0,
  `enviados` int(11) DEFAULT 0,
  `fallidos` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `hora_inicio_envio` int(11) DEFAULT NULL COMMENT 'Hora inicio envío para esta campaña',
  `hora_fin_envio` int(11) DEFAULT NULL COMMENT 'Hora fin envío para esta campaña',
  `media_url` text DEFAULT NULL,
  `media_type` varchar(100) DEFAULT NULL,
  `contactos_por_tanda` int(11) DEFAULT 15,
  `pendientes` int(11) DEFAULT 0,
  `delay_entre_tandas` int(11) DEFAULT 180,
  `filtro_tipo` varchar(50) DEFAULT 'todos',
  `iniciada_at` datetime DEFAULT NULL,
  `completada_at` datetime DEFAULT NULL,
  `errores_consecutivos` int(11) DEFAULT 0,
  `total_enviados_sesion` int(11) DEFAULT 0,
  `ultimo_descanso_at` datetime DEFAULT NULL,
  `auto_pausada` tinyint(1) DEFAULT 0,
  `grupo_campana` varchar(50) DEFAULT NULL,
  `grupo_nombre` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campanas_estado` (`estado`),
  KEY `idx_campana_estado` (`estado`),
  KEY `idx_campana_instancia` (`instancia_id`),
  KEY `idx_grupo` (`grupo_campana`),
  CONSTRAINT `campanas_ibfk_1` FOREIGN KEY (`instancia_id`) REFERENCES `instancias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campanas_contactos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `campanas_contactos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campana_id` int(11) NOT NULL,
  `contacto_id` int(11) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `estado` enum('pendiente','enviado','fallido') DEFAULT 'pendiente',
  `enviado_at` datetime DEFAULT NULL,
  `error_mensaje` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `campana_id` (`campana_id`),
  KEY `contacto_id` (`contacto_id`),
  CONSTRAINT `campanas_contactos_ibfk_1` FOREIGN KEY (`campana_id`) REFERENCES `campanas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `campanas_contactos_ibfk_2` FOREIGN KEY (`contacto_id`) REFERENCES `contactos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `config_campanas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `config_campanas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hora_inicio` int(11) DEFAULT 8 COMMENT 'Hora inicio envíos (0-23)',
  `hora_fin` int(11) DEFAULT 21 COMMENT 'Hora fin envíos (0-23)',
  `dias_semana` varchar(20) DEFAULT '1,2,3,4,5,6' COMMENT '1=Lun, 7=Dom',
  `max_por_hora` int(11) DEFAULT 200 COMMENT 'Máximo mensajes por hora',
  `activo` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `max_por_dia` int(11) DEFAULT 500,
  `pausa_cada_n` int(11) DEFAULT 30,
  `pausa_minutos` int(11) DEFAULT 5,
  `max_errores_consecutivos` int(11) DEFAULT 5,
  `delay_progresivo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contactos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contactos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `empresa` varchar(255) DEFAULT NULL,
  `etiquetas` varchar(500) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `origen` enum('manual','importado','sync','chat') DEFAULT 'manual',
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_instancia_numero` (`instancia_id`,`numero`),
  KEY `idx_contactos_numero` (`numero`),
  CONSTRAINT `contactos_ibfk_1` FOREIGN KEY (`instancia_id`) REFERENCES `instancias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contactos_grupos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contactos_grupos` (
  `contacto_id` int(11) NOT NULL,
  `grupo_id` int(11) NOT NULL,
  PRIMARY KEY (`contacto_id`,`grupo_id`),
  KEY `grupo_id` (`grupo_id`),
  CONSTRAINT `contactos_grupos_ibfk_1` FOREIGN KEY (`contacto_id`) REFERENCES `contactos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contactos_grupos_ibfk_2` FOREIGN KEY (`grupo_id`) REFERENCES `grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_atajos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_atajos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `nombre` varchar(100) NOT NULL COMMENT 'Nombre del atajo (ej: Saludo, Catálogo)',
  `atajo` varchar(50) DEFAULT NULL COMMENT 'Comando corto opcional (ej: /saludo)',
  `tipo` enum('text','image','document','audio') NOT NULL DEFAULT 'text',
  `contenido` text DEFAULT NULL COMMENT 'Texto del mensaje o caption',
  `archivo_path` varchar(500) DEFAULT NULL COMMENT 'Ruta del archivo en servidor',
  `archivo_nombre` varchar(255) DEFAULT NULL COMMENT 'Nombre original del archivo',
  `archivo_mime` varchar(100) DEFAULT NULL COMMENT 'MIME type del archivo',
  `orden` int(11) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_instancia` (`instancia_id`),
  KEY `idx_activo` (`activo`),
  KEY `idx_usuario` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_auto_respuesta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_auto_respuesta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(100) DEFAULT 'Mi respuesta',
  `activa` tinyint(1) DEFAULT 1,
  `mensaje` text DEFAULT NULL,
  `media_files` text DEFAULT NULL,
  `solo_nuevos` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_biblioteca_archivos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_biblioteca_archivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `instancia_id` int(11) NOT NULL,
  `nombre` varchar(200) NOT NULL COMMENT 'Nombre visible para el usuario',
  `descripcion` varchar(500) DEFAULT NULL,
  `tipo` enum('image','document','audio','video') DEFAULT 'document',
  `archivo_path` varchar(500) NOT NULL,
  `archivo_nombre` varchar(255) NOT NULL COMMENT 'Nombre original del archivo',
  `archivo_mime` varchar(100) NOT NULL,
  `orden` int(11) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_categoria` (`categoria_id`),
  KEY `idx_instancia` (`instancia_id`),
  CONSTRAINT `crm_biblioteca_archivos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `crm_biblioteca_categorias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_biblioteca_categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_biblioteca_categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `icono` varchar(10) DEFAULT '?',
  `orden` int(11) DEFAULT 0,
  `activa` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_instancia` (`instancia_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `modo_distribucion` enum('round_robin','least_chats') DEFAULT 'round_robin' COMMENT '1y1 o menor cantidad',
  `auto_asignar` tinyint(1) DEFAULT 1 COMMENT 'Asignar leads automáticamente',
  `max_chats_por_usuario` int(11) DEFAULT 20 COMMENT 'Máx chats activos por usuario',
  `notificar_nuevo_lead` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `auto_reasignar` tinyint(1) DEFAULT 0,
  `timeout_respuesta_min` int(11) DEFAULT 60 COMMENT 'Minutos sin respuesta para reasignar',
  `notificar_timeout` tinyint(1) DEFAULT 1 COMMENT 'Notificar al admin cuando se reasigna',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_etiquetas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_etiquetas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `color` varchar(7) DEFAULT '#6c757d' COMMENT 'Color hex del badge',
  `orden` int(11) DEFAULT 0,
  `activa` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_lead_etiquetas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_lead_etiquetas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `etiqueta_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_lead_etiqueta` (`lead_id`,`etiqueta_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_etiqueta` (`etiqueta_id`)
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `contacto_id` int(11) DEFAULT NULL,
  `numero` varchar(20) NOT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `foto_url` varchar(500) DEFAULT NULL,
  `usuario_asignado_id` int(11) DEFAULT NULL COMMENT 'Vendedor asignado',
  `estado` enum('nuevo','asignado','cerrado_positivo','cerrado_negativo') DEFAULT 'nuevo',
  `observacion` text DEFAULT NULL COMMENT 'Nota al cerrar negativo',
  `ultimo_mensaje` text DEFAULT NULL,
  `ultimo_mensaje_at` datetime DEFAULT NULL,
  `ultimo_mensaje_direccion` varchar(10) DEFAULT NULL,
  `mensajes_sin_leer` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `asignado_at` datetime DEFAULT NULL COMMENT 'Cuando se asigno al vendedor',
  `primer_respuesta_at` datetime DEFAULT NULL COMMENT 'Cuando el vendedor respondio por primera vez',
  `tiempo_respuesta_seg` int(11) DEFAULT NULL COMMENT 'Segundos entre asignacion y primera respuesta',
  `reasignado` tinyint(1) DEFAULT 0 COMMENT 'Fue reasignado por timeout',
  `reasignado_de_usuario_id` int(11) DEFAULT NULL COMMENT 'Usuario anterior (si fue reasignado)',
  `reasignado_at` datetime DEFAULT NULL,
  `auto_respuesta_enviada` tinyint(1) DEFAULT 0,
  `marcado` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_instancia_numero` (`instancia_id`,`numero`),
  KEY `idx_usuario` (`usuario_asignado_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_marcado` (`marcado`)
) ENGINE=InnoDB AUTO_INCREMENT=602 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_mensajes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_mensajes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `instancia_id` int(11) NOT NULL,
  `direccion` enum('entrante','saliente') NOT NULL,
  `tipo` enum('text','image','document','audio','video','location','contact','sticker') DEFAULT 'text',
  `contenido` text DEFAULT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `mensaje_id_wa` varchar(100) DEFAULT NULL COMMENT 'ID del mensaje en WhatsApp',
  `metadata` text DEFAULT NULL COMMENT 'JSON con datos extra: ad context, archivo info, etc.',
  `enviado_por_usuario_id` int(11) DEFAULT NULL COMMENT 'Quién envió (si saliente)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_instancia` (`instancia_id`),
  KEY `idx_mensaje_wa` (`mensaje_id_wa`)
) ENGINE=InnoDB AUTO_INCREMENT=2778 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_reasignaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_reasignaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `instancia_id` int(11) NOT NULL,
  `usuario_anterior_id` int(11) NOT NULL,
  `usuario_nuevo_id` int(11) DEFAULT NULL,
  `motivo` varchar(255) DEFAULT 'timeout',
  `tiempo_sin_respuesta_min` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_fecha` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crm_round_robin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_round_robin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `ultimo_usuario_id` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_instancia` (`instancia_id`)
) ENGINE=InnoDB AUTO_INCREMENT=358 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cron_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) DEFAULT NULL,
  `mensaje` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `difusion_envios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `difusion_envios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lista_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo` enum('mensaje','estado') DEFAULT 'mensaje',
  `contenido` text DEFAULT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `delay_min` int(11) DEFAULT 5,
  `delay_max` int(11) DEFAULT 15,
  `estado` enum('pendiente','enviando','completado','cancelado') DEFAULT 'pendiente',
  `programado_para` datetime DEFAULT NULL,
  `total` int(11) DEFAULT 0,
  `enviados` int(11) DEFAULT 0,
  `fallidos` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `difusion_lista_contactos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `difusion_lista_contactos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lista_id` int(11) NOT NULL,
  `contacto_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lista_contacto` (`lista_id`,`contacto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `difusion_listas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `difusion_listas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `total_contactos` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `difusiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `difusiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `mensaje` text NOT NULL,
  `imagen_url` varchar(500) DEFAULT NULL,
  `contactos_ids` text DEFAULT NULL,
  `total_contactos` int(11) DEFAULT 0,
  `enviados` int(11) DEFAULT 0,
  `fallidos` int(11) DEFAULT 0,
  `delay_min` int(11) DEFAULT 5,
  `delay_max` int(11) DEFAULT 15,
  `estado` enum('borrador','programada','enviando','completada','cancelada') DEFAULT 'borrador',
  `programada_para` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `envios_diarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `envios_diarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `total_enviados` int(11) DEFAULT 0,
  `total_fallidos` int(11) DEFAULT 0,
  `ultima_hora_enviados` int(11) DEFAULT 0,
  `ultima_hora` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_instancia_fecha` (`instancia_id`,`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `envios_masivos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `envios_masivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo` enum('mensaje','estado','difusion') DEFAULT 'mensaje',
  `mensaje` text DEFAULT NULL,
  `imagen_url` varchar(500) DEFAULT NULL,
  `instancias_ids` text DEFAULT NULL,
  `total_instancias` int(11) DEFAULT 0,
  `completadas` int(11) DEFAULT 0,
  `estado` enum('pendiente','procesando','completado','cancelado') DEFAULT 'pendiente',
  `programado_para` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `estados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo` enum('texto','imagen','video') DEFAULT 'texto',
  `contenido` text DEFAULT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `background_color` varchar(7) DEFAULT '#075E54',
  `font_type` int(11) DEFAULT 0,
  `programado_para` datetime DEFAULT NULL,
  `estado` enum('pendiente','publicado','fallido','cancelado') DEFAULT 'pendiente',
  `publicado_at` datetime DEFAULT NULL,
  `error_mensaje` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `grupos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `grupos` (
  `instancia_id` int(11) DEFAULT 1,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `color` varchar(7) DEFAULT '#3498db',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `instancias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `instancias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `api_key` varchar(255) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `numero` varchar(20) DEFAULT NULL,
  `nombre_perfil` varchar(255) DEFAULT NULL,
  `estado` enum('conectado','desconectado','escaneando') DEFAULT 'desconectado',
  `es_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `errores_consecutivos` int(11) DEFAULT 0,
  `bloqueada` tinyint(1) DEFAULT 0,
  `bloqueada_at` datetime DEFAULT NULL,
  `bloqueo_motivo` varchar(500) DEFAULT NULL,
  `ultimo_envio_ok` datetime DEFAULT NULL,
  `ultimo_error` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mensajes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mensajes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instancia_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `contacto_id` int(11) DEFAULT NULL,
  `numero` varchar(20) NOT NULL,
  `tipo` enum('texto','imagen','documento','audio','video') DEFAULT 'texto',
  `contenido` text DEFAULT NULL,
  `media_url` varchar(500) DEFAULT NULL,
  `direccion` enum('enviado','recibido') DEFAULT 'enviado',
  `estado` enum('pendiente','enviado','entregado','leido','fallido') DEFAULT 'pendiente',
  `campana_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `instancia_id` (`instancia_id`),
  KEY `campana_id` (`campana_id`),
  KEY `idx_mensajes_fecha` (`created_at`),
  CONSTRAINT `mensajes_ibfk_1` FOREIGN KEY (`instancia_id`) REFERENCES `instancias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mensajes_ibfk_2` FOREIGN KEY (`campana_id`) REFERENCES `campanas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plantillas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `plantillas` (
  `usuario_id` int(11) DEFAULT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `mensaje` text NOT NULL,
  `variables` text DEFAULT NULL COMMENT 'JSON con variables disponibles',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `es_global` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `responsable` varchar(100) DEFAULT NULL COMMENT 'Nombre de quien maneja esta cuenta',
  `rol` enum('admin','usuario') DEFAULT 'usuario',
  `activo` tinyint(1) DEFAULT 1,
  `ultimo_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `crm_online` tinyint(1) DEFAULT 0,
  `crm_activo` tinyint(1) DEFAULT 1 COMMENT 'Participa en distribución CRM',
  PRIMARY KEY (`id`),
  UNIQUE KEY `usuario` (`usuario`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios_instancias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios_instancias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `instancia_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_asignacion` (`usuario_id`,`instancia_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;


-- ============================================
-- Usuario admin por defecto (password: admin123 — CAMBIAR!)
-- ============================================
INSERT IGNORE INTO `usuarios` (`nombre`, `usuario`, `password`, `rol`, `activo`) 
VALUES ('Administrador', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);
