<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait NormalizesLocalizedSlugs
{
    /**
     * Пустой slug → из названия; непустой → Str::slug() от ввода.
     * Для не-языка по умолчанию: пустое имя и пустой slug → null (строка не создаётся).
     */
    protected function mergeLocalizedSlugsFromRequest(Request $request, $languages): void
    {
        foreach ($languages as $language) {
            $suffix = $language->code;
            $name = trim((string) $request->input('name_'.$suffix, ''));
            $slugRaw = $request->input('slug_'.$suffix);
            $slugTrimmed = $slugRaw === null ? '' : trim((string) $slugRaw);

            if (! $language->is_default && $name === '' && $slugTrimmed === '') {
                $request->merge(['slug_'.$suffix => null]);

                continue;
            }

            if ($slugTrimmed === '') {
                $request->merge(['slug_'.$suffix => Str::slug($name)]);
            } else {
                $request->merge(['slug_'.$suffix => Str::slug($slugTrimmed)]);
            }
        }
    }
}
