<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\NormalizesLocalizedSlugs;
use App\Http\Controllers\Controller;
use App\Models\ExtractionPrompt;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\ProductDescription;
use App\Models\PromptCategory;
use App\Models\PromptCategoryDescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromptCategoryController extends Controller
{
    use NormalizesLocalizedSlugs;

    public function index()
    {
        $pageTitle = 'Промты';
        $defaultLanguage = Language::getDefault();
        $aiFieldOptions = ProductDescription::aiFieldLabels();
        $categories = PromptCategory::with(['parent.descriptions', 'descriptions', 'manufacturer'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(15);
        $extractionPrompt = ExtractionPrompt::active()
            ?? ExtractionPrompt::query()->orderByDesc('id')->first();

        return view('admin.prompt_categories.index', compact('categories', 'pageTitle', 'defaultLanguage', 'aiFieldOptions', 'extractionPrompt'));
    }

    public function create()
    {
        $pageTitle = 'Промты - Создание промпта';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $manufacturers = Manufacturer::query()->orderBy('sort_order')->orderBy('name')->get();
        $aiFieldOptions = ProductDescription::aiFieldLabels();

        return view('admin.prompt_categories.create', compact('pageTitle', 'languages', 'defaultLanguage', 'manufacturers', 'aiFieldOptions'));
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык.');
        }

        $rules = [
            'manufacturer_id' => ['required', 'exists:manufacturers,id'],
            'ai_field' => ['required', Rule::in(ProductDescription::aiFieldKeys())],
            'row_data' => ['nullable', 'string'],
            'stage_1_extraction' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = 'required|string|max:255';
            $rules['slug_'.$suffix] = [
                'required',
                'string',
                'max:255',
                Rule::unique('prompt_category_descriptions', 'slug')->where('language_id', $language->id),
            ];
            $rules['description_'.$suffix] = 'required|string';
            $rules['stage_2_live_'.$suffix] = 'required|string';
            $rules['stage_3_edit_'.$suffix] = 'required|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages) {
            $category = PromptCategory::create([
                'manufacturer_id' => $request->input('manufacturer_id'),
                'ai_field' => $request->input('ai_field'),
                'row_data' => $request->input('row_data'),
                'stage_1_extraction' => $request->input('stage_1_extraction'),
                'image' => null,
                'top' => false,
                'column' => 0,
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);

                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    continue;
                }

                $slug = (string) $slugInput;
                if ($slug === '') {
                    continue;
                }

                PromptCategoryDescription::create([
                    'prompt_category_id' => $category->id,
                    'language_id' => $language->id,
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'description' => $request->input('description_'.$suffix),
                    'stage_2_live' => $request->input('stage_2_live_'.$suffix),
                    'stage_3_edit' => $request->input('stage_3_edit_'.$suffix),
                ]);
            }

            PromptCategory::rebuildPaths();
        });

        return redirect()->route('admin.prompt-categories.index')->with('success', 'Промпт создан');
    }

    public function edit(string $id)
    {
        $pageTitle = 'Промты - Редактирование промпта';
        $category = PromptCategory::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $manufacturers = Manufacturer::query()->orderBy('sort_order')->orderBy('name')->get();
        $aiFieldOptions = ProductDescription::aiFieldLabels();

        return view('admin.prompt_categories.edit', compact('pageTitle', 'category', 'languages', 'defaultLanguage', 'manufacturers', 'aiFieldOptions'));
    }

    public function update(Request $request, string $id)
    {
        $category = PromptCategory::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык.');
        }

        $rules = [
            'manufacturer_id' => ['required', 'exists:manufacturers,id'],
            'ai_field' => ['required', Rule::in(ProductDescription::aiFieldKeys())],
            'row_data' => ['nullable', 'string'],
            'stage_1_extraction' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $description = $category->descriptions->firstWhere('language_id', $language->id);
            $rules['name_'.$suffix] = 'required|string|max:255';
            $rules['slug_'.$suffix] = [
                'required',
                'string',
                'max:255',
                Rule::unique('prompt_category_descriptions', 'slug')
                    ->where('language_id', $language->id)
                    ->ignore($description?->id),
            ];
            $rules['description_'.$suffix] = 'required|string';
            $rules['stage_2_live_'.$suffix] = 'required|string';
            $rules['stage_3_edit_'.$suffix] = 'required|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $category) {
            $category->update([
                'manufacturer_id' => $request->input('manufacturer_id'),
                'ai_field' => $request->input('ai_field'),
                'row_data' => $request->input('row_data'),
                'stage_1_extraction' => $request->input('stage_1_extraction'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = $request->input('name_'.$suffix, '');
                $slugInput = $request->input('slug_'.$suffix);

                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '')) {
                    PromptCategoryDescription::query()
                        ->where('prompt_category_id', $category->id)
                        ->where('language_id', $language->id)
                        ->delete();
                    continue;
                }

                $slug = (string) $slugInput;
                if ($slug === '') {
                    continue;
                }

                PromptCategoryDescription::updateOrCreate(
                    [
                        'prompt_category_id' => $category->id,
                        'language_id' => $language->id,
                    ],
                    [
                        'name' => $name ?: $slug,
                        'slug' => $slug,
                        'description' => $request->input('description_'.$suffix),
                        'stage_2_live' => $request->input('stage_2_live_'.$suffix),
                        'stage_3_edit' => $request->input('stage_3_edit_'.$suffix),
                    ]
                );
            }

            PromptCategory::rebuildPaths();
        });

        return redirect()
            ->route('admin.prompt-categories.edit', $category->id)
            ->with('success', 'Промпт обновлен');
    }

    public function destroy(string $id)
    {
        PromptCategory::findOrFail($id)->delete();
        PromptCategory::rebuildPaths();

        return redirect()->route('admin.prompt-categories.index')->with('success', 'Промпт удален');
    }

    public function updateRawData(Request $request, string $id)
    {
        $data = $request->validate([
            'row_data' => ['nullable', 'string'],
        ]);

        $category = PromptCategory::findOrFail($id);
        $category->update([
            'row_data' => $data['row_data'] ?? null,
        ]);

        return redirect()->route('admin.prompt-categories.index')->with('success', 'Нотация к сырью обновлена');
    }

    public function updateExtractionPrompt(Request $request)
    {
        $data = $request->validate([
            'prompt_text' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($data) {
            ExtractionPrompt::query()->update(['is_active' => false]);

            ExtractionPrompt::updateOrCreate(
                ['key' => 'default'],
                [
                    'name' => 'Промт для выжимки',
                    'prompt_text' => $data['prompt_text'],
                    'is_active' => true,
                ]
            );
        });

        return redirect()
            ->route('admin.prompt-categories.index')
            ->with('success', 'Промт для выжимки обновлён');
    }
}
