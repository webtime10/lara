<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    private function lang(): Language
    {
        $lang = Language::getDefault();
        abort_if(! $lang, 503, 'Не задан язык по умолчанию в админке.');

        return $lang;
    }

    /** Главная: корневые категории (как витрина OC). */
    public function index(): View
    {
        $lang = $this->lang();
        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('status', true)
            ->orderBy('sort_order')
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)])
            ->get();

        return view('catalog.index', compact('categories', 'lang'));
    }

    /** Страница категории: подкатегории + товары. */
    public function category(Request $request, string $slug): View
    {
        $lang = $this->lang();
        $catDesc = CategoryDescription::query()
            ->where('language_id', $lang->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $category = Category::query()
            ->where('id', $catDesc->category_id)
            ->where('status', true)
            ->firstOrFail();

        $children = Category::query()
            ->where('parent_id', $category->id)
            ->where('status', true)
            ->orderBy('sort_order')
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)])
            ->get();

        $productsQuery = $category->products()
            ->where('status', true)
            ->orderByDesc('id')
            ->with([
                'descriptions' => fn ($q) => $q->where('language_id', $lang->id),
                'manufacturer',
                'author',
                'categories.descriptions' => fn ($q) => $q->where('language_id', $lang->id),
            ]);

        $selectedAuthor = $request->query('author');
        if ($selectedAuthor) {
            $productsQuery->where('author_id', (int) $selectedAuthor);
        }

        $selectedCategory = $request->query('category');
        if ($selectedCategory) {
            $productsQuery->whereHas('categories', function ($q) use ($selectedCategory) {
                $q->where('categories.id', (int) $selectedCategory);
            });
        }

        $selectedManufacturer = $request->query('manufacturer');
        if ($selectedManufacturer) {
            $productsQuery->where('manufacturer_id', (int) $selectedManufacturer);
        }

        $selectedMonth = $request->query('month');
        if ($selectedMonth && preg_match('/^\d{4}-\d{2}$/', $selectedMonth) === 1) {
            [$year, $month] = explode('-', $selectedMonth);
            $productsQuery
                ->whereYear('created_at', (int) $year)
                ->whereMonth('created_at', (int) $month);
        }

        $products = $productsQuery->get();

        $authors = User::query()
            ->where(function ($q) {
                $q->whereNotNull('role_id')
                    ->orWhereNotNull('role');
            })
            ->whereIn('id', Product::query()->whereNotNull('author_id')->pluck('author_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        $filterCategories = Category::query()
            ->where('status', true)
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)])
            ->orderBy('sort_order')
            ->get();

        $manufacturers = Manufacturer::query()
            ->whereIn('id', Product::query()->whereNotNull('manufacturer_id')->pluck('manufacturer_id')->unique())
            ->orderBy('name')
            ->get(['id', 'name']);

        $months = Product::query()
            ->whereNotNull('created_at')
            ->orderByDesc('created_at')
            ->get(['created_at'])
            ->map(fn ($p) => optional($p->created_at)->format('Y-m'))
            ->filter()
            ->unique()
            ->values();

        $breadcrumb = $this->buildCategoryBreadcrumb($category, $lang);

        return view('catalog.category', compact(
            'category',
            'catDesc',
            'children',
            'products',
            'lang',
            'breadcrumb',
            'authors',
            'filterCategories',
            'manufacturers',
            'months',
            'selectedAuthor',
            'selectedCategory',
            'selectedManufacturer',
            'selectedMonth'
        ));
    }

    /** Карточка товара. */
    public function product(string $slug): View
    {
        $lang = $this->lang();
        $prodDesc = ProductDescription::query()
            ->where('language_id', $lang->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $product = Product::query()
            ->where('id', $prodDesc->product_id)
            ->where('status', true)
            ->with([
                'descriptions' => fn ($q) => $q->where('language_id', $lang->id),
                'manufacturer',
                'author',
                'categories.descriptions' => fn ($q) => $q->where('language_id', $lang->id),
            ])
            ->firstOrFail();

        return view('catalog.product', compact('product', 'prodDesc', 'lang'));
    }

    /** Цепочка категорий для хлебных крошек. */
    private function buildCategoryBreadcrumb(Category $category, Language $lang): array
    {
        $chain = [];
        $current = $category;
        while ($current) {
            $current->loadMissing(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)]);
            $d = $current->descriptions->firstWhere('language_id', $lang->id);
            array_unshift($chain, [
                'id' => $current->id,
                'name' => $d->name ?? ('#'.$current->id),
                'slug' => $d->slug ?? null,
            ]);
            $current = $current->parent_id ? Category::find($current->parent_id) : null;
        }

        return $chain;
    }
}
