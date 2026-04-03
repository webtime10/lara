<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDescription extends Model
{
    protected $fillable = [
        'product_id',
        'language_id',
        'name',
        'slug',
        'description',
        'ai_text_about_the_country',
        'ai_reviews_from_tourists',
        'tag',
        'meta_title',
        'meta_description',
        'meta_keyword',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Поле в БД хранит JSON {title, text_1, text_2} или старый текст/HTML — для вывода в шаблоне.
     */
    public function aiStructuredFieldHtml(string $attribute): string
    {
        $value = $this->getAttribute($attribute);
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        $data = json_decode($value, true);
        if (is_array($data) && (array_key_exists('text_1', $data) || array_key_exists('text_2', $data) || array_key_exists('title', $data))) {
            $title = trim((string) ($data['title'] ?? ''));
            $text1 = trim((string) ($data['text_1'] ?? ''));
            $text2 = trim((string) ($data['text_2'] ?? ''));

            $html = '';
            if ($title !== '') {
                $html .= '<p><strong>'.e($title).'</strong></p>'."\n\n";
            }

            return $html.$text1.($text1 !== '' && $text2 !== '' ? "\n\n" : '').$text2;
        }

        return nl2br(e($value));
    }
}
