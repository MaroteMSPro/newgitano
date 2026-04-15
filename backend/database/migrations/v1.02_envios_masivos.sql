-- v1.02: Tabla para log de envíos masivos

CREATE TABLE IF NOT EXISTS `envios_masivos` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `instancia_id` INT          NOT NULL,
  `total`        INT          DEFAULT 0,
  `enviados`     INT          DEFAULT 0,
  `errores`      INT          DEFAULT 0,
  `mensaje`      TEXT,
  `estado`       ENUM('pendiente','procesando','completado','error') DEFAULT 'pendiente',
  `iniciado_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `finalizado_at`DATETIME     DEFAULT NULL,
  INDEX (`instancia_id`),
  INDEX (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
