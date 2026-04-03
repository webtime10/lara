-- Сообщение: «У пользователя не назначена роль» — выполните в phpMyAdmin (своя база Laravel).

-- Вариант A: по email
UPDATE `users`
SET
  `role_id` = (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1),
  `role` = 'admin',
  `updated_at` = NOW()
WHERE `email` = 'akvamarin01@admin.local';

-- Вариант B: по имени (если входите как akvamarin01 без email)
UPDATE `users`
SET
  `role_id` = (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1),
  `role` = 'admin',
  `updated_at` = NOW()
WHERE `name` = 'akvamarin01';

-- Проверка: в таблице roles должна быть строка со slug = admin (после миграций / RoleSeeder).
-- SELECT id, slug FROM roles;
