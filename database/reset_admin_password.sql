-- ============================================================
-- Resetear contraseña del admin a: Admin123!
-- Ejecutar en phpMyAdmin sobre la BD del sistema.
-- ============================================================

UPDATE `users`
SET
  `email` = 'admin@bellezatotal.co',
  `password` = '$2y$10$4SaKTOic3dqjlCECiawL9u/68HsTaR1LFqAH3bUolDhG.rI4N8lyq',
  `role` = 'admin',
  `status` = 'active',
  `updated_at` = NOW()
WHERE `role` = 'admin'
   OR `email` IN ('admin@bellezatotal.co', 'admin@erpseri.com')
ORDER BY `id`
LIMIT 1;

-- Si no quedó ningún admin, créelo
INSERT INTO `users` (`name`, `email`, `password`, `role`, `status`)
SELECT 'Administrador', 'admin@bellezatotal.co',
       '$2y$10$4SaKTOic3dqjlCECiawL9u/68HsTaR1LFqAH3bUolDhG.rI4N8lyq',
       'admin', 'active'
WHERE NOT EXISTS (
  SELECT 1 FROM `users` WHERE `email` = 'admin@bellezatotal.co' AND `status` = 'active'
);

-- Verificar
SELECT `id`, `name`, `email`, `role`, `status`, LEFT(`password`, 7) AS hash_prefix
FROM `users`;
