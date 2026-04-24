-- v1.05: Flag es_recordatorio en crm_mensajes

ALTER TABLE `crm_mensajes`
  ADD COLUMN IF NOT EXISTS `es_recordatorio` TINYINT(1) DEFAULT 0 AFTER `enviado_por_usuario_id`;

CREATE INDEX IF NOT EXISTS `idx_mensajes_recordatorio` ON `crm_mensajes` (`es_recordatorio`);
