<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Category extends Model
{
    protected $fillable = [
        'image',
        'parent_id',
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
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function descriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product');
    }

    public function descriptionForLanguage(int $languageId): ?CategoryDescription
    {
        return $this->descriptions->firstWhere('language_id', $languageId);
    }

    /**
     * ID всех вложенных категорий (чтобы не выбрать себя/потомка родителем).
     */
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

    /**
     * Список для select «Родитель»: дерево с отступами, как в OpenCart.
     *
     * @param  array<int>  $excludeIds  id, которые нельзя выбрать (себя и потомков при редактировании)
     */
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
            $items = $all->filter(function ($c) use ($parentId) {
                if ($parentId === null) {
                    return $c->parent_id === null;
                }

                return (int) $c->parent_id === (int) $parentId;
            })->sortBy('sort_order')->values();

            foreach ($items as $cat) {
                if (in_array((int) $cat->id, array_map('intval', $excludeIds), true)) {
                    continue;
                }
                $d = $cat->descriptions->firstWhere('language_id', $lang->id);
                $label = str_repeat('— ', $depth).($d->name ?? ('#'.$cat->id));
                $rows->push(['id' => $cat->id, 'label' => $label]);
                $walk($cat->id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $rows;
    }

    /**
     * Пересобрать category_paths для всего дерева (после изменений).
     */
    public static function rebuildPaths(): void
    {
        DB::table('category_paths')->delete();
        $categories = static::all();
        foreach ($categories as $category) {
            $chain = [];
            $current = $category;
            while ($current) {
                array_unshift($chain, $current->id);
                $current = $current->parent_id ? static::find($current->parent_id) : null;
            }
            foreach ($chain as $level => $pathId) {
                DB::table('category_paths')->insert([
                    'category_id' => $category->id,
                    'path_id' => $pathId,
                    'level' => $level,
                ]);
            }
        }
    }
}
