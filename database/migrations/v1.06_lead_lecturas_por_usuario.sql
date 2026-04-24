-- v1.06: Track de mensajes no leídos por usuario (en lugar de global)
-- Tabla que guarda el último mensaje visto por cada usuario en cada lead

CREATE TABLE IF NOT EXISTS `lead_lecturas` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `lead_id`      INT NOT NULL,
  `usuario_id`   INT NOT NULL,
  `ultimo_visto` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_lead_usuario` (`lead_id`, `usuario_id`),
  INDEX (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
