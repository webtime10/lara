{{-- 
    Шаблон страницы редактирования товара в админ‑панели.
    Здесь:
    - показывается форма редактирования товара (модель, категории, описания на разных языках);
    - есть спец‑поле source_text (сырьё для AI);
    - по кнопке "Сгенерировать контент..." отправляется AJAX‑запрос в админ‑контроллер,
      который кладёт задачу в очередь (Redis), а фоновый worker обновляет описания товара.
--}}
@extends('admin.layouts.layout')

@section('content')
    {{-- Хедер страницы: заголовок и хлебные крошки --}}
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>{{ $pageTitle }}</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Товары</a></li>
                        <li class="breadcrumb-item active">Редактирование</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            {{-- Основная карточка с формой редактирования товара --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Данные товара</h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.products.index') }}" class="btn btn-default btn-sm">
                            <i class="fas fa-reply"></i> Назад
                        </a>
                        <button type="submit" form="productForm" class="btn btn-primary btn-sm" title="Сохранить" aria-label="Сохранить">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
                </div>
                {{-- 
                    Основная форма редактирования товара.
                    - отправляется на маршрут admin.products.update (метод PUT в ProductController@update);
                    - НЕ отвечает за запуск AI — за это отвечает AJAX‑скрипт ниже.
                --}}
                <form id="productForm" action="{{ route('admin.products.update', $product->id) }}" method="post">
                    @csrf @method('PUT')
                    <div class="card-body">
                        @if ($errors->any())
                            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                        @endif

                        {{-- 
                            Выбранные категории товара.
                            - берём либо значения из old() (если форма вернулась с ошибками),
                              либо текущие категории товара из связи $product->categories.
                        --}}
                        @php $sel = old('category_ids', $product->categories->pluck('id')->all()); @endphp
                        <div class="form-group">
                            <label>Категории <span class="text-danger">*</span></label>
                            <div style="max-height:200px;overflow:auto;border:1px solid #ddd;padding:10px;border-radius:4px;">
                                @foreach($categories as $cat)
                                    @php $d = $defaultLanguage ? $cat->descriptions->firstWhere('language_id', $defaultLanguage->id) : null; @endphp
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="category_ids[]" value="{{ $cat->id }}" id="c{{ $cat->id }}"
                                            {{ in_array($cat->id, $sel) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="c{{ $cat->id }}">{{ $d->name ?? '#'.$cat->id }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Блок параметров товара: модель, SKU и производитель --}}
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="model">Model <span class="text-danger">*</span></label>
                                    <input type="text" name="model" id="model" class="form-control" value="{{ old('model', $product->model) }}" required maxlength="64">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="sku">SKU</label>
                                    <input type="text" name="sku" id="sku" class="form-control" value="{{ old('sku', $product->sku) }}" maxlength="64">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="manufacturer_id">Производитель</label>
                                    <select name="manufacturer_id" id="manufacturer_id" class="form-control">
                                        <option value="">—</option>
                                        @foreach($manufacturers as $m)
                                            <option value="{{ $m->id }}" {{ old('manufacturer_id', $product->manufacturer_id) == $m->id ? 'selected' : '' }}>{{ $m->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        {{-- Цена / Количество / Порядок скрыты из формы. Колонки сохранены в БД. --}}
                        {{-- Картинка (путь) скрыта из формы. Колонка сохранена в БД. --}}

                        {{-- 
                            Вкладки с локализованными описаниями.
                            Для каждого языка есть своя вкладка и свой набор полей:
                            - name_<код>
                            - slug_<код>
                            - description_<код>
                            Сами данные берутся из связей $product->descriptions и значений old().
                        --}}
                        <h5>Описания</h5>
                        <ul class="nav nav-tabs" role="tablist">
                            @foreach($languages as $i => $language)
                                <li class="nav-item">
                                    <a class="nav-link {{ $i === 0 ? 'active' : '' }}" data-toggle="tab" href="#lang{{ $language->id }}">{{ $language->name }}</a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="tab-content border p-3">
                            @foreach($languages as $i => $language)
                                @php
                                    $c = $language->code;
                                    $desc = $product->descriptions->firstWhere('language_id', $language->id);
                                    $aiCountryForForm = \App\Support\AiDescriptionJsonNormalizer::normalize($desc?->ai_text_about_the_country) ?? '';
                                    $aiReviewsForForm = \App\Support\AiDescriptionJsonNormalizer::normalize($desc?->ai_reviews_from_tourists) ?? '';
                                @endphp
                                <div class="tab-pane fade {{ $i === 0 ? 'show active' : '' }}" id="lang{{ $language->id }}">
                                    <div class="form-group">
                                        <label for="name_{{ $c }}">Название</label>
                                        <input type="text" name="name_{{ $c }}" id="name_{{ $c }}" class="form-control" value="{{ old('name_'.$c, $desc->name ?? '') }}" {{ $language->is_default ? 'required' : '' }}>
                                    </div>
                                    <div class="form-group">
                                        <label for="slug_{{ $c }}">Slug</label>
                                        <input type="text" name="slug_{{ $c }}" id="slug_{{ $c }}" class="form-control" value="{{ old('slug_'.$c, $desc->slug ?? '') }}"
                                               data-slug-locked="{{ ($desc && ($desc->slug ?? '') !== '') ? '1' : '0' }}" autocomplete="off">
                                        <small class="form-text text-muted">Пустой — из названия. Очистите поле, чтобы снова подтягивать из названия.</small>
                                    </div>
                                   <!-- <div class="form-group">
                                        <label>Описание</label>
                                        <textarea name="description_{{ $c }}" class="form-control" rows="4">{{ old('description_'.$c, $desc->description ?? '') }}</textarea>
                                    </div> -->
                                    <div class="form-group">
                                        <label for="ai_text_about_the_country_{{ $c }}">Текст о стране (JSON в БД: title, text_1, text_2)</label>
                                        <textarea name="ai_text_about_the_country_{{ $c }}" id="ai_text_about_the_country_{{ $c }}" class="form-control" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.875rem;" rows="18" spellcheck="false">{!! old('ai_text_about_the_country_'.$c, $aiCountryForForm) !!}</textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="ai_reviews_from_tourists_{{ $c }}">Отзывы туристов (JSON в БД: title, text_1, text_2)</label>
                                        <textarea name="ai_reviews_from_tourists_{{ $c }}" id="ai_reviews_from_tourists_{{ $c }}" class="form-control" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.875rem;" rows="18" spellcheck="false">{!! old('ai_reviews_from_tourists_'.$c, $aiReviewsForForm) !!}</textarea>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="form-group mt-3">
                            <label for="ai_field_selector">AI поля (из БД, префикс ai_)</label>
                            <select id="ai_field_selector" class="form-control">
                                @forelse($aiFields as $aiField => $aiFieldLabel)
                                    <option value="{{ $aiField }}">{{ $aiFieldLabel }}</option>
                                @empty
                                    <option value="">Нет ai_ полей</option>
                                @endforelse
                            </select>
                        </div>
                        {{-- 
                            Блок для AI:
                            - textarea source_text хранит "сырой" текст (например, отзывы, описания);
                            - по кнопке "Сгенерировать контент..." этот текст отправляется в бэкенд,
                              где создаётся задание в очереди и фоновой worker генерирует переводы.
                        --}}
                        <div class="form-group mt-3 p-3" style="background: #e6f0ff; border-radius: 8px; border: 1px solid #b3c6ff;">
                            <label for="source_text"><i class="fas fa-file-alt"></i> Исходное сырьё для AI (отзывы, описания, факты)</label>
                            <textarea
                                name="source_text"
                                id="source_text_input" 
                                class="form-control"
                                rows="6"
                                style="background:#f5f8ff;border-color:#b3c6ff;"
                                placeholder="Вставьте сюда текст со швейцарских сайтов или соцсетей..."
                            >{{ old('source_text', $product->source_text) }}</textarea>

                            <label for="result_textarea" class="mt-3"><i class="fas fa-check-circle"></i> Результат (Result)</label>
                            <textarea
                                name="result"
                                id="result_textarea"
                                class="form-control"
                                rows="6"
                                style="background:#eef4ff;border-color:#b3c6ff;"
                                placeholder="Буфер для итогового текста (например, результат AI)..."
                            >{{ old('result', $product->result) }}</textarea>
                            
                            <div class="mt-2">
                                {{-- Кнопка запуска AI‑генерации. НЕ отправляет форму, а вызывает AJAX‑запрос ниже. --}}
                                <button type="button" id="btn-generate-ai" class="btn btn-success">
                                    <i class="fas fa-robot"></i> Сгенерировать контент для всех языков
                                </button>
                                {{-- Индикатор загрузки, показывается на время AJAX‑запроса к серверу. --}}
                                <div id="ai-loader" style="display:none;" class="ml-2 d-inline-block">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                    <span class="text-primary ml-1">Робот думает над переводом...</span>
                                </div>
                            </div>
                        </div>
                        {{-- Переключатель "Активен" — статус товара (boolean поле в модели Product) --}}
                        <div class="form-group mt-3">
                            <input type="hidden" name="status" value="0">
                            <label><input type="checkbox" name="status" value="1" {{ old('status', $product->status) ? 'checked' : '' }}> Активен</label>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    {{-- Подключаем общий JS‑фрагмент, который синхронизирует name/slug между собой. --}}
    @include('admin.partials.slug-auto-sync')

    {{-- 
        JS‑логика запуска AI:
        - берёт текст из textarea source_text_input;
        - отправляет AJAX‑POST на маршрут admin.products.generate_ai;
        - на бэкенде этот запрос попадает в ProductController@generateAi,
          где создаётся job TranslateProductJob и отправляется в очередь (Redis).
    --}}
    <script>
    $(function () {
        // Обработка клика по кнопке "Сгенерировать контент для всех языков"
        $('#btn-generate-ai').on('click', function () {
            var sourceText = $('#source_text_input').val();
            var resultText = $('#result_textarea').val();
            var targetAiField = $('#ai_field_selector').val();
            var $btn = $(this);
            var $loader = $('#ai-loader');

            // Минимальная длина текста для генерации — 10 символов
            if (!sourceText || $.trim(sourceText).length < 10) {
                alert('Слишком короткий текст для генерации. Добавьте больше данных!');
                return;
            }
            if (!targetAiField) {
                alert('Выберите AI поле для записи результата.');
                return;
            }

            // Защита от случайного запуска: предупреждаем, что будет перезаписано выбранное AI-поле.
            if (!confirm('Это перезапишет выбранное AI поле для всех языков. Продолжить?')) {
                return;
            }

            // На время запроса блокируем кнопку (чтобы не нажимали несколько раз подряд)
            // и показываем индикатор загрузки рядом с ней.
            $btn.prop('disabled', true);
            $loader.show();

            // AJAX‑запрос к маршруту admin.products.generate_ai (см. web.php и ProductController@generateAi)
            $.ajax({
                url: "{{ route('admin.products.generate_ai') }}",
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                data: {
                    product_id: {{ $product->id }},
                    source_text: sourceText,
                    result_text: resultText,
                    ai_field: targetAiField
                }
            })
                // УСПЕШНЫЙ ОТВЕТ СЕРВЕРА (HTTP 200, без ошибок в контроллере)
                .done(function (data) {
                    // data — это JSON, который вернул контроллер ProductController@generateAi.
                    // Там сейчас возвращается: { "message": "Перевод запущен в фоне" }.
                    // Если message есть — показываем его, иначе используем запасной текст.
                    alert(data.message || 'Перевод запущен в фоне. Обновите страницу позже и нажмите "Сохранить".');
                })
                // ОТВЕТ С ОШИБКОЙ (например, валидация, 500, проблемы с Redis/OpenAI и т.п.)
                .fail(function (xhr) {
                    // xhr.responseJSON.message — стандартное поле message из JSON‑ответа Laravel при ошибке.
                    // Если оно есть — показываем его, если нет — выводим общий текст про ошибку сервера/лимиты.
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Ошибка сервера или лимиты API';
                    alert('Произошла ошибка: ' + msg);
                })
                // ВСЕГДА ВЫПОЛНЯЕТСЯ В КОНЦЕ, НЕЗАВИСИМО ОТ УСПЕХА/ОШИБКИ
                .always(function () {
                    // Возвращаем кнопку в рабочее состояние и прячем индикатор загрузки.
                    $btn.prop('disabled', false);
                    $loader.hide();
                });
        });
    });
    </script>
    @include('admin.partials.slug-auto-sync')
@endsection
