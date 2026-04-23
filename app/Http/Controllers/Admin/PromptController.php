<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\NormalizesLocalizedSlugs;
use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Prompt;
use App\Models\PromptCategory;
use App\Models\PromptDescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromptController extends Controller
{
    use NormalizesLocalizedSlugs;

    public function index()
    {
        $pageTitle = 'Промты';
        $defaultLanguage = Language::getDefault();
        $prompts = Prompt::with([
            'descriptions',
            'category.descriptions',
        ])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.prompts.index', compact('pageTitle', 'prompts', 'defaultLanguage'));
    }

    public function create()
    {
        $pageTitle = 'Промты - Создание';
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $categories = PromptCategory::with('descriptions')->orderBy('sort_order')->get();

        return view('admin.prompts.create', compact('pageTitle', 'languages', 'defaultLanguage', 'categories'));
    }

    public function store(Request $request)
    {
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык.');
        }

        $rules = [
            'prompt_category_id' => ['nullable', 'exists:prompt_categories,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['slug_'.$suffix] = [
                $language->is_default ? 'required' : 'nullable',
                'string',
                'max:255',
                Rule::unique('prompt_descriptions', 'slug')->where('language_id', $language->id),
            ];
            $rules['excerpt_'.$suffix] = 'nullable|string';
            $rules['prompt_text_'.$suffix] = $language->is_default ? 'required|string' : 'nullable|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages) {
            $prompt = Prompt::create([
                'prompt_category_id' => $request->input('prompt_category_id'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = trim((string) $request->input('name_'.$suffix, ''));
                $slugInput = $request->input('slug_'.$suffix);
                $text = trim((string) $request->input('prompt_text_'.$suffix, ''));

                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '') && $text === '') {
                    continue;
                }

                $slug = (string) $slugInput;
                if ($slug === '' || $text === '') {
                    continue;
                }

                PromptDescription::create([
                    'prompt_id' => $prompt->id,
                    'language_id' => $language->id,
                    'name' => $name ?: $slug,
                    'slug' => $slug,
                    'excerpt' => $request->input('excerpt_'.$suffix),
                    'prompt_text' => $request->input('prompt_text_'.$suffix),
                ]);
            }
        });

        return redirect()->route('admin.prompts.index')->with('success', 'Промт создан');
    }

    public function edit(string $id)
    {
        $pageTitle = 'Промты - Редактирование';
        $prompt = Prompt::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        $defaultLanguage = Language::getDefault();
        $categories = PromptCategory::with('descriptions')->orderBy('sort_order')->get();

        return view('admin.prompts.edit', compact('pageTitle', 'prompt', 'languages', 'defaultLanguage', 'categories'));
    }

    public function update(Request $request, string $id)
    {
        $prompt = Prompt::with('descriptions')->findOrFail($id);
        $languages = Language::forAdminForms();
        if ($languages->isEmpty()) {
            return redirect()->route('admin.languages.index')
                ->with('info', 'Добавьте хотя бы один язык.');
        }

        $rules = [
            'prompt_category_id' => ['nullable', 'exists:prompt_categories,id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];

        foreach ($languages as $language) {
            $suffix = $language->code;
            $description = $prompt->descriptions->firstWhere('language_id', $language->id);
            $rules['name_'.$suffix] = $language->is_default ? 'required|string|max:255' : 'nullable|string|max:255';
            $rules['slug_'.$suffix] = [
                $language->is_default ? 'required' : 'nullable',
                'string',
                'max:255',
                Rule::unique('prompt_descriptions', 'slug')
                    ->where('language_id', $language->id)
                    ->ignore($description?->id),
            ];
            $rules['excerpt_'.$suffix] = 'nullable|string';
            $rules['prompt_text_'.$suffix] = $language->is_default ? 'required|string' : 'nullable|string';
        }

        $this->mergeLocalizedSlugsFromRequest($request, $languages);
        $request->validate($rules);

        DB::transaction(function () use ($request, $languages, $prompt) {
            $prompt->update([
                'prompt_category_id' => $request->input('prompt_category_id'),
                'sort_order' => (int) $request->input('sort_order', 0),
                'status' => $request->boolean('status'),
            ]);

            foreach ($languages as $language) {
                $suffix = $language->code;
                $name = trim((string) $request->input('name_'.$suffix, ''));
                $slugInput = $request->input('slug_'.$suffix);
                $text = trim((string) $request->input('prompt_text_'.$suffix, ''));

                if (! $language->is_default && $name === '' && ($slugInput === null || $slugInput === '') && $text === '') {
                    PromptDescription::query()
                        ->where('prompt_id', $prompt->id)
                        ->where('language_id', $language->id)
                        ->delete();
                    continue;
                }

                $slug = (string) $slugInput;
                if ($slug === '' || $text === '') {
                    continue;
                }

                PromptDescription::updateOrCreate(
                    [
                        'prompt_id' => $prompt->id,
                        'language_id' => $language->id,
                    ],
                    [
                        'name' => $name ?: $slug,
                        'slug' => $slug,
                        'excerpt' => $request->input('excerpt_'.$suffix),
                        'prompt_text' => $request->input('prompt_text_'.$suffix),
                    ]
                );
            }
        });

        return redirect()->route('admin.prompts.index')->with('success', 'Промт обновлен');
    }

    public function destroy(string $id)
    {
        Prompt::findOrFail($id)->delete();

        return redirect()->route('admin.prompts.index')->with('success', 'Промт удален');
    }
}
