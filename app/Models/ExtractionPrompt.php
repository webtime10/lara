<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtractionPrompt extends Model
{
    protected $fillable = [
        'key',
        'name',
        'prompt_text',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function active(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }
}
