-- v1.03: Columna grupo_campana para campañas multi-instancia

ALTER TABLE `campanas`
  ADD COLUMN IF NOT EXISTS `grupo_campana`   VARCHAR(36)  DEFAULT NULL AFTER `nombre`,
  ADD COLUMN IF NOT EXISTS `multi_instancia` TINYINT(1)   DEFAULT 0   AFTER `grupo_campana`;

CREATE INDEX IF NOT EXISTS `idx_campanas_grupo` ON `campanas` (`grupo_campana`);
