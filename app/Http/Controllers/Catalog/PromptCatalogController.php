<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Prompt;
use App\Models\PromptCategory;
use App\Models\PromptCategoryDescription;
use App\Models\PromptDescription;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromptCatalogController extends Controller
{
    private function resolveLanguage(Request $request): Language
    {
        $requested = trim((string) $request->query('lang', ''));
        $query = Language::query()->where('is_active', true);

        if ($requested !== '') {
            $language = (clone $query)->where('code', $requested)->first();
            if ($language) {
                return $language;
            }
        }

        $fallback = Language::getDefault();
        abort_if(! $fallback, 503, 'Не задан язык по умолчанию.');

        return $fallback;
    }

    private function activeLanguages()
    {
        return Language::getActive();
    }

    public function index(Request $request): View
    {
        $lang = $this->resolveLanguage($request);
        $availableLanguages = $this->activeLanguages();
        $categories = PromptCategory::query()
            ->whereNull('parent_id')
            ->where('status', true)
            ->orderBy('sort_order')
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)])
            ->get();

        return view('catalog.prompts.index', compact('lang', 'availableLanguages', 'categories'));
    }

    public function category(Request $request, string $slug): View
    {
        $lang = $this->resolveLanguage($request);
        $availableLanguages = $this->activeLanguages();
        $categoryDescription = PromptCategoryDescription::query()
            ->where('language_id', $lang->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $category = PromptCategory::query()
            ->where('id', $categoryDescription->prompt_category_id)
            ->where('status', true)
            ->firstOrFail();

        $children = PromptCategory::query()
            ->where('parent_id', $category->id)
            ->where('status', true)
            ->orderBy('sort_order')
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)])
            ->get();

        $prompts = Prompt::query()
            ->where('prompt_category_id', $category->id)
            ->where('status', true)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->with(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)])
            ->get();

        $breadcrumb = $this->buildCategoryBreadcrumb($category, $lang);

        return view('catalog.prompts.category', compact(
            'lang',
            'availableLanguages',
            'category',
            'categoryDescription',
            'children',
            'prompts',
            'breadcrumb'
        ));
    }

    public function prompt(Request $request, string $slug): View
    {
        $lang = $this->resolveLanguage($request);
        $availableLanguages = $this->activeLanguages();
        $promptDescription = PromptDescription::query()
            ->where('language_id', $lang->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $prompt = Prompt::query()
            ->where('id', $promptDescription->prompt_id)
            ->where('status', true)
            ->with([
                'descriptions' => fn ($q) => $q->where('language_id', $lang->id),
                'category.descriptions' => fn ($q) => $q->where('language_id', $lang->id),
            ])
            ->firstOrFail();

        return view('catalog.prompts.prompt', compact('lang', 'availableLanguages', 'prompt', 'promptDescription'));
    }

    private function buildCategoryBreadcrumb(PromptCategory $category, Language $lang): array
    {
        $chain = [];
        $current = $category;

        while ($current) {
            $current->loadMissing(['descriptions' => fn ($q) => $q->where('language_id', $lang->id)]);
            $description = $current->descriptions->firstWhere('language_id', $lang->id);

            array_unshift($chain, [
                'id' => $current->id,
                'name' => $description->name ?? ('#'.$current->id),
                'slug' => $description->slug ?? null,
            ]);

            $current = $current->parent_id ? PromptCategory::find($current->parent_id) : null;
        }

        return $chain;
    }
}
