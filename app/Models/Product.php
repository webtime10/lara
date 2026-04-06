<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /**
     * Поля, которые можно массово заполнять через create()/update().
     * Здесь в том числе есть source_text — сырьё для AI‑генерации описаний товара.
     */
    protected $fillable = [
        'model',
        'sku',
        'image',
        'manufacturer_id',
        'author_id',
        'source_text',
        'result',
        'ai_status',
        'status',
    ];

    /**
     * Приведение типов полей модели.
     * status хранится в БД как int/tinyint, но в коде будет работать как boolean.
     */
    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Производитель товара.
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    /**
     * Автор (пользователь системы), который создал/отредактировал товар.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Все локализованные описания товара (по разным языкам).
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class);
    }

    /**
     * Категории, к которым принадлежит товар (связь многие‑ко‑многим).
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }
}
