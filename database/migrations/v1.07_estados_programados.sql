-- v1.07: Estados (Stories) programados con cronología por usuario

CREATE TABLE IF NOT EXISTS `estados_programados` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `instancia_id` INT          NOT NULL,
  `usuario_id`   INT          NOT NULL,
  `tipo`         ENUM('text','image') DEFAULT 'text',
  `contenido`    TEXT         NOT NULL,
  `caption`      VARCHAR(500) DEFAULT NULL,
  `fecha_hora`   DATETIME     NOT NULL,
  `estado`       ENUM('pendiente','publicado','error','cancelado') DEFAULT 'pendiente',
  `error_msg`    TEXT         DEFAULT NULL,
  `publicado_at` DATETIME     DEFAULT NULL,
  `es_masivo`    TINYINT(1)   DEFAULT 0,
  `grupo_masivo` VARCHAR(36)  DEFAULT NULL,
  `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX (`fecha_hora`, `estado`),
  INDEX (`usuario_id`),
  INDEX (`instancia_id`),
  INDEX (`grupo_masivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
