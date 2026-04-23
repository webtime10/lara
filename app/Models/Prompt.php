<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    protected $fillable = [
        'prompt_category_id',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PromptCategory::class, 'prompt_category_id');
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(PromptDescription::class);
    }
}
