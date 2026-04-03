<?php

namespace App\Support;

/**
 * Приводит значение AI-поля к JSON { title, text_1, text_2 } для хранения в БД.
 * Уже JSON — нормализует и перекодирует. HTML/текст (старый формат) — разбирает эвристически.
 */
final class AiDescriptionJsonNormalizer
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (self::isOurJsonShape($decoded)) {
            return self::reencodeJson($decoded);
        }

        return self::legacyToJson($trimmed);
    }

    private static function isOurJsonShape(mixed $decoded): bool
    {
        if (! is_array($decoded)) {
            return false;
        }

        return array_key_exists('title', $decoded)
            || array_key_exists('text_1', $decoded)
            || array_key_exists('text_2', $decoded);
    }

    private static function reencodeJson(array $data): string
    {
        $payload = [
            'title' => trim((string) ($data['title'] ?? '')),
            'text_1' => trim((string) ($data['text_1'] ?? '')),
            'text_2' => trim((string) ($data['text_2'] ?? '')),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private static function legacyToJson(string $html): string
    {
        $title = '';
        $rest = $html;

        if (preg_match('#^\s*<p[^>]*>\s*<strong[^>]*>(.*?)</strong>\s*</p>#ius', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $rest = trim((string) preg_replace('#^\s*<p[^>]*>\s*<strong[^>]*>.*?</strong>\s*</p>#ius', '', $html, 1));
        }

        if ($rest !== '' && preg_match_all('#<p[^>]*>.*?</p>#ius', $rest, $matches)) {
            $blocks = $matches[0];
            $n = count($blocks);
            if ($n === 0) {
                return self::reencodeJson(['title' => $title, 'text_1' => $rest, 'text_2' => '']);
            }
            $mid = (int) ceil($n / 2);
            $part1 = implode("\n\n", array_slice($blocks, 0, $mid));
            $part2 = implode("\n\n", array_slice($blocks, $mid));

            return self::reencodeJson(['title' => $title, 'text_1' => $part1, 'text_2' => $part2]);
        }

        return self::reencodeJson(['title' => $title, 'text_1' => $rest, 'text_2' => '']);
    }
}
