<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromptCategoryDescription extends Model
{
    protected $fillable = [
        'prompt_category_id',
        'language_id',
        'name',
        'slug',
        'description',
        'stage_2_live',
        'stage_3_edit',
    ];

    public function promptCategory(): BelongsTo
    {
        return $this->belongsTo(PromptCategory::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
