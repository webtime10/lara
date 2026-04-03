-- Выполните в phpMyAdmin, если UPDATE по email дал «0 строк» — пользователя ещё не было.
-- Убедитесь, что выбрана база этого Laravel-проекта и таблица называется `users`.

INSERT INTO `users` (
  `name`,
  `email`,
  `password`,
  `role_id`,
  `role`,
  `email_verified_at`,
  `remember_token`,
  `created_at`,
  `updated_at`
)
VALUES (
  'akvamarin01',
  'akvamarin01@admin.local',
  '$2y$12$8hyovRNcIA.4yp3JoZnh1.rEHn5f.SI8JSUCZ5RApBh2zWQF8yCTq',
  (SELECT `id` FROM `roles` WHERE `slug` = 'admin' LIMIT 1),
  'admin',
  NULL,
  NULL,
  NOW(),
  NOW()
);

-- Если появится ошибка дубликата email — сначала:
-- SELECT * FROM `users`;
-- и удалите/измените конфликтующую строку или используйте UPDATE по реальному email из таблицы.
