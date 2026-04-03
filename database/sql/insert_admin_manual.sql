-- Ручная вставка/обновление админа в MySQL (phpMyAdmin).
-- 1) Сначала получите хеш пароля в консоли проекта:
--    php artisan password:hash sandra2201
-- 2) Скопируйте строку хеша и подставьте вместо PASTE_BCRYPT_HASH ниже.

-- Привязать роль admin и пароль существующему пользователю по email:
UPDATE `users`
SET
  `password` = 'PASTE_BCRYPT_HASH',
  `role_id` = (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1),
  `name` = 'akvamarin01',
  `role` = 'admin',
  `updated_at` = NOW()
WHERE `email` = 'akvamarin01@admin.local';

-- Если записи с таким email ещё нет — выполните INSERT (подставьте тот же хеш):
-- INSERT INTO `users` (`name`, `email`, `password`, `role`, `role_id`, `email_verified_at`, `remember_token`, `created_at`, `updated_at`)
-- VALUES (
--   'akvamarin01',
--   'akvamarin01@admin.local',
--   'PASTE_BCRYPT_HASH',
--   'admin',
--   (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1),
--   NULL,
--   NULL,
--   NOW(),
--   NOW()
-- );
