<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('extraction_prompts')) {
            return;
        }

        Schema::create('extraction_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->longText('prompt_text');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::table('extraction_prompts')->insert([
            'key' => 'default',
            'name' => 'Выжимка по умолчанию',
            'prompt_text' => <<<'PROMPT'
Тебе дан большой исходный текст об объекте (городе, регионе, маршруте, достопримечательности).

Задача: сжать его в плотный фактологический дайджест без потери информации.

Что ОСТАВИТЬ:
- все имена собственные, географические названия, бренды;
- все цифры, цены, расписания, телефоны, адреса, координаты;
- характеристики маршрутов (длина, время, перепад высот);
- виды транспорта, тарифы, проездные;
- сезонные особенности, погоду;
- практические советы (что взять, что надеть, когда ехать);
- сравнения с соседними местами;
- любые факты, которые могут пригодиться для туристической статьи.

Что УБРАТЬ:
- повторы и дубли (PDF-оглавления, страничные заголовки, нумерацию страниц);
- маркетинговую воду без конкретики;
- упоминания самих источников, авторов, копирайтов;
- технический шум извлечения (поломанные переносы, «| Travel Guide» и т.п.).

Чего НЕ делать:
- НЕ переводить (язык оригинала сохраняем как есть);
- НЕ дополнять фактами от себя;
- НЕ сокращать ради краткости — сохраняй ВСЁ ценное.

Формат вывода:
- Структурированный текст с заголовками (## раздел) и списками (- пункт);
- Без вступлений, без выводов, без комментариев «вот ваша выжимка».
- Только сами факты.
PROMPT,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_prompts');
    }
};
