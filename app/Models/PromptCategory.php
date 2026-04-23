<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PromptCategory extends Model
{
    protected $fillable = [
        'image',
        'parent_id',
        'manufacturer_id',
        'ai_field',
        'row_data',
        'stage_1_extraction',
        'top',
        'column',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'top' => 'boolean',
        'status' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(PromptCategoryDescription::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }

    public function descriptionForLanguage(int $languageId): ?PromptCategoryDescription
    {
        return $this->descriptions->firstWhere('language_id', $languageId);
    }

    public function descendantIdList(): array
    {
        $this->loadMissing('children');
        $ids = [];

        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->descendantIdList());
        }

        return $ids;
    }

    public static function treeForParentSelect(?Language $lang = null, array $excludeIds = []): Collection
    {
        $lang = $lang ?? Language::getDefault();
        if (! $lang) {
            return collect();
        }

        $all = static::with(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)])
            ->orderBy('sort_order')
            ->get();

        $rows = collect();

        $walk = function ($parentId, $depth) use (&$walk, $all, $lang, &$rows, $excludeIds) {
            $items = $all->filter(function ($category) use ($parentId) {
                if ($parentId === null) {
                    return $category->parent_id === null;
                }

                return (int) $category->parent_id === (int) $parentId;
            })->sortBy('sort_order')->values();

            foreach ($items as $category) {
                if (in_array((int) $category->id, array_map('intval', $excludeIds), true)) {
                    continue;
                }

                $description = $category->descriptions->firstWhere('language_id', $lang->id);
                $label = str_repeat('— ', $depth).($description->name ?? ('#'.$category->id));
                $rows->push(['id' => $category->id, 'label' => $label]);
                $walk($category->id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $rows;
    }

    public static function rebuildPaths(): void
    {
        DB::table('prompt_category_paths')->delete();

        foreach (static::all() as $category) {
            $chain = [];
            $current = $category;

            while ($current) {
                array_unshift($chain, $current->id);
                $current = $current->parent_id ? static::find($current->parent_id) : null;
            }

            foreach ($chain as $level => $pathId) {
                DB::table('prompt_category_paths')->insert([
                    'prompt_category_id' => $category->id,
                    'path_id' => $pathId,
                    'level' => $level,
                ]);
            }
        }
    }
}
