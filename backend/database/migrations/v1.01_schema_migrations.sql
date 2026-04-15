-- v1.01: Tabla de control de migraciones aplicadas
-- Esta migración debe ser la primera en correr siempre

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `version`    VARCHAR(20)  NOT NULL UNIQUE,
  `nombre`     VARCHAR(255) NOT NULL,
  `applied_at` DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
