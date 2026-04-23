<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    protected $fillable = [
        'code',
        'name',
        'locale',
        'directory',
        'image',
        'sort_order',
        'status',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'status' => 'boolean',
    ];

    public function categoryDescriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class);
    }

    public function promptCategoryDescriptions(): HasMany
    {
        return $this->hasMany(PromptCategoryDescription::class);
    }

    public function promptDescriptions(): HasMany
    {
        return $this->hasMany(PromptDescription::class);
    }

    public static function getActive()
    {
        return static::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Языки для форм админки: если все «неактивны», берём любые — иначе поля названия не рисуются.
     */
    public static function forAdminForms()
    {
        $langs = static::getActive();
        if ($langs->isEmpty()) {
            $langs = static::query()->orderBy('sort_order')->orderBy('id')->get();
        }

        return $langs;
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)
            ->where('is_active', true)
            ->first();
    }
}
