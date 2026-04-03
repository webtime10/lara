-- Четыре языка: русский (по умолчанию), английский, иврит, арабский.
-- Выполняйте в phpMyAdmin (кодировка соединения: utf8mb4).
--
-- Внимание: если у языка с кодом `ua` уже есть описания категорий/товаров,
-- строка DELETE ниже удалит и эти описания (CASCADE). В таком случае
-- закомментируйте DELETE или перенесите данные вручную.

SET NAMES utf8mb4;

-- Убрать старый украинский из сидера (если был), если он не нужен:
DELETE FROM `languages` WHERE `code` = 'ua';

-- Сбросить «по умолчанию» у всех, затем выставить одному (ниже):
UPDATE `languages` SET `is_default` = 0;

-- Русский (основной язык сайта)
UPDATE `languages` SET
  `name` = 'Русский',
  `locale` = 'ru-RU',
  `directory` = 'ru-ru',
  `sort_order` = 1,
  `status` = 1,
  `is_default` = 1,
  `is_active` = 1,
  `updated_at` = NOW()
WHERE `code` = 'ru';

INSERT INTO `languages` (`name`, `code`, `locale`, `directory`, `image`, `sort_order`, `status`, `is_default`, `is_active`, `created_at`, `updated_at`)
SELECT 'Русский', 'ru', 'ru-RU', 'ru-ru', NULL, 1, 1, 1, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `languages` WHERE `code` = 'ru' LIMIT 1);

-- English
UPDATE `languages` SET
  `name` = 'English',
  `locale` = 'en-GB',
  `directory` = 'en-gb',
  `sort_order` = 2,
  `status` = 1,
  `is_default` = 0,
  `is_active` = 1,
  `updated_at` = NOW()
WHERE `code` = 'en';

INSERT INTO `languages` (`name`, `code`, `locale`, `directory`, `image`, `sort_order`, `status`, `is_default`, `is_active`, `created_at`, `updated_at`)
SELECT 'English', 'en', 'en-GB', 'en-gb', NULL, 2, 1, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `languages` WHERE `code` = 'en' LIMIT 1);

-- Hebrew (עברית)
UPDATE `languages` SET
  `name` = 'עברית',
  `locale` = 'he-IL',
  `directory` = 'he-il',
  `sort_order` = 3,
  `status` = 1,
  `is_default` = 0,
  `is_active` = 1,
  `updated_at` = NOW()
WHERE `code` = 'he';

INSERT INTO `languages` (`name`, `code`, `locale`, `directory`, `image`, `sort_order`, `status`, `is_default`, `is_active`, `created_at`, `updated_at`)
SELECT 'עברית', 'he', 'he-IL', 'he-il', NULL, 3, 1, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `languages` WHERE `code` = 'he' LIMIT 1);

-- Arabic (العربية)
UPDATE `languages` SET
  `name` = 'العربية',
  `locale` = 'ar-SA',
  `directory` = 'ar-sa',
  `sort_order` = 4,
  `status` = 1,
  `is_default` = 0,
  `is_active` = 1,
  `updated_at` = NOW()
WHERE `code` = 'ar';

INSERT INTO `languages` (`name`, `code`, `locale`, `directory`, `image`, `sort_order`, `status`, `is_default`, `is_active`, `created_at`, `updated_at`)
SELECT 'العربية', 'ar', 'ar-SA', 'ar-sa', NULL, 4, 1, 0, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `languages` WHERE `code` = 'ar' LIMIT 1);

-- Ровно один язык по умолчанию — русский:
UPDATE `languages` SET `is_default` = 0;
UPDATE `languages` SET `is_default` = 1 WHERE `code` = 'ru';
