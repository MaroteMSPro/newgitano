-- v1.04: Sistema de recordatorios de mensajes

CREATE TABLE IF NOT EXISTS `recordatorios` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id`      INT          NOT NULL,
  `instancia_id` INT          NOT NULL,
  `usuario_id`   INT          NOT NULL,
  `numero`       VARCHAR(30)  NOT NULL,
  `mensaje`      TEXT         NOT NULL,
  `fecha_hora`   DATETIME     NOT NULL,
  `estado`       ENUM('pendiente','enviado','error','cancelado') DEFAULT 'pendiente',
  `error_msg`    TEXT         DEFAULT NULL,
  `enviado_at`   DATETIME     DEFAULT NULL,
  `created_at`   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX (`fecha_hora`, `estado`),
  INDEX (`lead_id`),
  INDEX (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
