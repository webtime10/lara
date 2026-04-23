{{-- 
    Шаблон страницы редактирования поста в админ‑панели.
    Здесь:
    - показывается форма редактирования поста (модель, категории, описания на разных языках);
    - есть спец‑поле source_text (сырьё для AI);
    - по кнопке "Сгенерировать контент..." отправляется AJAX‑запрос в админ‑контроллер,
      который кладёт задачу в очередь (Redis), а фоновый worker обновляет описания поста.
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
                        <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Посты</a></li>
                        <li class="breadcrumb-item active">Редактирование</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            {{-- Основная карточка с формой редактирования поста --}}
            <div class="card">
                <div class="card-header">
                    <button
                        type="button"
                        id="btn-wp-publish"
                        class="btn btn-primary btn-sm"
                        data-product-id="{{ $product->id }}"
                        title="Publish to WordPress"
                        aria-label="Publish to WordPress"
                    >
                        <i class="fab fa-wordpress"></i> WordPress
                    </button>
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
                    Основная форма редактирования поста.
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
                            Выбранные категории поста.
                            - берём либо значения из old() (если форма вернулась с ошибками),
                              либо текущие категории поста из связи $product->categories.
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

                        {{-- Блок параметров поста: модель, SKU и сайт --}}
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
                                    <label for="manufacturer_id">Сайт</label>
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
                                    @foreach($aiFields as $fieldKey => $fieldLabel)
                                    <div class="form-group">
                                        <div class="wrap-ai-status-indicator d-flex align-items-center mb-2">
                                            
                                            <label class="mb-0 mr-2">{{ $fieldLabel }}</label>
                                            
                                            <a href="javascript:void(0)" class="ai-status-indicator" data-field="{{ $fieldKey }}">
                                                <i class="fas fa-circle text-secondary"></i>
                                            </a>
                                
                                        </div>
                                
                                        <textarea 
                                            name="{{ $fieldKey }}_{{ $c }}" 
                                            class="form-control" 
                                            rows="18"
                                        >{!! old($fieldKey.'_'.$c, $desc->{$fieldKey} ?? '') !!}</textarea>
                                    </div>
                                @endforeach
                                </div>
                            @endforeach
                        </div>

                        {{-- 
                            Блок для AI:
                            - textarea source_text хранит "сырой" текст (например, отзывы, описания);
                            - по кнопке "Сгенерировать контент..." этот текст отправляется в бэкенд,
                              где создаётся задание в очереди и фоновой worker генерирует переводы.
                        --}}
                        <div class="form-group mt-3 p-3" style="background: #e6f0ff; border-radius: 8px; border: 1px solid #b3c6ff;">
                            <label for="source_text"><i class="fas fa-file-alt"></i> Исходное сырьё для AI (отзывы, описания, факты)</label>
                            <div class="mb-2 d-flex flex-wrap align-items-center gap-2 small">
                                <input type="file" id="source_text_file" name="file" accept=".pdf,.docx,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain" class="d-none">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="source_text_attach_btn" title="PDF, DOCX, TXT">
                                    <i class="fas fa-paperclip"></i> Прикрепить файл
                                </button>
                                <span class="text-muted">Текст из файла добавится в поле ниже.</span>
                            </div>
                            <textarea
                                name="source_text"
                                id="source_text_input" 
                                class="form-control"
                                rows="6"
                                style="background:#f5f8ff;border-color:#0644ff;"
                                placeholder="Вставьте сюда текст со швейцарских сайтов или соцсетей..."
                            >{{ old('source_text', $product->source_text) }}</textarea>
                            <div class="wrap-ai-status-indicator" style="display:none;">
                                <label for="result_textarea" class="mt-3"><span><i class="fas fa-check-circle"></i> Результат (Result)</span>

                                </label>
                            </div>   
                            <textarea
                                name="result"
                                id="result_textarea"
                                class="form-control"
                                rows="6"
                                style="display:none;background:#eef4ff;border-color:#b3c6ff;"
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
                        {{-- Переключатель "Активен" — статус поста (boolean поле в модели Product) --}}
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
    @php
        $aiCheckExpectedLanguages = config('ai.generation.expected_languages', ['ru', 'en', 'he', 'ar']);
    @endphp
    <script>
    
    // jQuery(document).ready — выполняем весь код ниже после построения DOM (поля формы уже в дереве).
    $(function () {
        // Кэшируем jQuery-обёртку textarea «исходное сырьё для AI» (id задан в разметке выше).
        var $sourceTa = $('#source_text_input');
        // Строка, которой временно подменяем содержимое поля на время чтения файла на сервере.
        var sourceFileReadMsg = 'Читаю файл, подождите...';
        // Вешаем обработчик на кнопку «Прикрепить файл» — сам input[type=file] скрыт (class d-none).
        $('#source_text_attach_btn').on('click', function () { // кликаю по кнопке а срабатывает инпут
            // Программно «нажимаем» скрытый input — откроется системный диалог выбора файла.
            $('#source_text_file').trigger('click');
        });
        // После выбора файла срабатывает change у нативного <input type="file">.
        $('#source_text_file').on('change', function () {
            // Внутри обработчика this — DOM-элемент file-input (не jQuery-объект).
            var fileInput = this;
            // Берём первый файл из FileList; если списка нет — получим undefined.
            var file = fileInput.files && fileInput.files[0];
            // Пользователь мог отменить диалог или очистить выбор — не шлём AJAX.
            if (!file) {
                return;
            }
            // Сохраняем текущий текст, чтобы после ответа сервера дописать извлечённое, а не затереть.
            var previousVal = $sourceTa.val();
            // Блокируем редактирование и показываем «Читаю файл…», чтобы не правили параллельно.
            $sourceTa.prop('disabled', true).val(sourceFileReadMsg);
            // FormData нужен для multipart: файл уйдёт как бинарное тело, не как строка в JSON.
            var formData = new FormData();
            // Имя поля «file» должно совпадать с тем, что ожидает маршрут admin.products.extract_text.
            formData.append('file', file);
            // Отправляем файл на сервер для извлечения текста (PDF/DOCX/TXT обрабатывает бэкенд).

// здесь закидываем текст или файл аякс возвращет то сто закинуто промежуточный этап перд отправкой

            $.ajax({
                // Относительный URL (второй аргумент route false) — та же схема/хост, что у страницы (важно для cookie сессии).
                url: "{{ route('admin.products.extract_text', [], false) }}",
                // Метод POST — загрузка файла и побочные эффекты на сервере.
                method: 'POST',
                // Заголовки: CSRF для Laravel, X-Requested-With — признак AJAX, Accept — просим JSON.
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                // Тело запроса — наш FormData с файлом.
                data: formData,
                // Не превращать data в строку querystring (иначе сломается загрузка файла).
                processData: false,
                // Не подставлять application/x-www-form-urlencoded — браузер сам поставит multipart boundary.
                contentType: false
            })
                // Успешный HTTP-ответ: в res ожидается объект с полем text (извлечённый текст).
                .done(function (res) {
                    // Берём текст из ответа или пустую строку, если структура неожиданная.
                    var chunk = (res && res.text) ? res.text : '';
                    // Разделитель между старым и новым: двойной перенос, если старый не заканчивается на \n; иначе один \n или пусто.
                    var sep = (previousVal && !/\n$/.test(previousVal)) ? '\n\n' : (previousVal ? '\n' : '');
                    // Возвращаем поле в режим редактирования и дописываем извлечённый фрагмент к прежнему содержимому.
                    $sourceTa.prop('disabled', false).val(previousVal + sep + chunk);
                })
                // Ошибка сети/валидации/500 — показываем message из JSON или код статуса, восстанавливаем textarea.
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : ('Ошибка ' + (xhr.status || '') );
                    $sourceTa.prop('disabled', false).val(previousVal);
                    alert(msg);
                })
                // В любом исходе сбрасываем value у file-input, чтобы повторный выбор того же файла снова вызвал change.
                .always(function () {
                    fileInput.value = '';
                });
        });

        var aiFieldKeys = @json(array_keys($aiFields));
        var indicatorSelector = '.ai-status-indicator[data-field="%FIELD%"] i';

        function setFieldIndicatorState(field, status) {
            var $fieldIndicators = $(indicatorSelector.replace('%FIELD%', field));
            if (!$fieldIndicators.length) {
                return;
            }
            if (status === 'success') {
                $fieldIndicators.removeClass('text-warning text-danger text-secondary').addClass('text-success');
            } else if (status === 'error') {
                $fieldIndicators.removeClass('text-warning text-success text-secondary').addClass('text-danger');
            } else {
                $fieldIndicators.removeClass('text-success text-danger text-secondary').addClass('text-warning');
            }
        }

        function setAllIndicatorsState(status) {
            aiFieldKeys.forEach(function (field) {
                setFieldIndicatorState(field, status);
            });
        }

        // Клик по «Сгенерировать контент для всех языков» — старт генерации по всем AI-полям сразу.
        $('#btn-generate-ai').on('click', function () {

            // Сырьё для AI: Source или, если он пустой, поле «Результат» (как на бэкенде при сохранении в product_descriptions.result).
            var sourceText = $('#source_text_input').val();
            var resultText = $('#result_textarea').val();
            // this внутри handler — сама нажатая кнопка; оборачиваем в jQuery для .prop и т.д.
            var $btn = $(this);
            // Тот же спиннер, что и в обработчике change селекта.
            var $loader = $('#ai-loader');

            // Минимум 10 символов: достаточно заполнить Source или только Result.
            var rawForAi = $.trim(sourceText || '') || $.trim(resultText || '');
            if (!rawForAi || rawForAi.length < 10) {
                alert('Слишком короткий текст для генерации. Заполните «Исходное сырьё» или «Результат» (не меньше 10 символов).');
                return;
            }
            // Если в конфиге нет ai-полей — не отправляем запрос.
            if (!aiFieldKeys.length) {
                alert('Нет AI-полей для генерации.');
                return;
            }

            // Подтверждение: будет перезапись всех AI-полей во всех языках.
            if (!confirm('Это перезапишет все AI-поля для всех языков. Продолжить?')) {
                return;
            }

            // Первая блокировка UI до ответа generate_ai (защита от двойного клика).
            $btn.prop('disabled', true);
            $loader.show();

        // Ставим "в процессе" для всех AI-индикаторов.
        setAllIndicatorsState('processing');

        // Повторно фиксируем disabled/лоадер (дублирует первичную блокировку, но гарантирует состояние после смены классов иконок).
        $btn.prop('disabled', true);
        $loader.show();




            // AJAX‑запрос к маршруту admin.products.generate_ai (см. web.php и ProductController@generateAi)
            $.ajax({
               /*-- Относительный путь: POST уходит на тот же хост и схему (http/https), что и страница.
                     Иначе при APP_URL=https, а вход по http, route() даст https — cookie сессии не уйдёт → Unauthenticated. */
                url: "{{ route('admin.products.generate_ai', [], false) }}",
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                data: {
                    product_id: {{ $product->id }},
                    source_text: sourceText,
                    result_text: resultText
                }
            })
                // УСПЕШНЫЙ ОТВЕТ СЕРВЕРА (HTTP 200, без ошибок в контроллере)
                .done(function (data) {
    alert(data.message || 'Процесс запущен в фоне');
    
    // Ссылка на проверку (маршрут, который ты добавил в web.php)
// заходит в метод проверок и узнает состояние светафора

    var checkUrl = "{{ route('admin.products.check_ai_status', $product->id, false) }}";
    
    // Опрос: зелёный только если в БД появился новый контент по всем AI-полям.
    var pollAttempts = 0;
    {{-- Интервал 5 с; держим опрос дольше server-side таймаута (config ai.generation.timeout_seconds) --}}
    var maxPollAttempts = {{ (int) ceil((int) config('ai.generation.timeout_seconds', 3600) / 5) + 120 }};
    var pollTimer = setInterval(function () {
        pollAttempts += 1;
        if (pollAttempts > maxPollAttempts) {
            clearInterval(pollTimer);
            setAllIndicatorsState('error');
            alert('Генерация не завершилась за отведённое время. Проверьте логи (очередь, OPENAI_API_KEY / php artisan config:clear).');
            $('#btn-generate-ai').prop('disabled', false);
            $('#ai-loader').hide();
            return;
        }
        $.ajax({
            url: checkUrl,
            method: 'GET',
            data: {
                languages: @json($aiCheckExpectedLanguages)
            },
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            success: function (res) {
                if (res && res.fields) {
                    aiFieldKeys.forEach(function(field) {
                        var fieldPayload = res.fields[field] || {};
                        setFieldIndicatorState(field, fieldPayload.status || 'processing');
                    });
                }

                if (res && res.status === 'error') {
                    clearInterval(pollTimer);
                    var hintParts = [];
                    if (res.fields) {
                        aiFieldKeys.forEach(function (f) {
                            var p = res.fields[f] || {};
                            if (p.status === 'error' && p.error_reason) {
                                hintParts.push(f + ': ' + p.error_reason);
                            }
                        });
                    }
                    var hint = hintParts.length ? ('\n\n' + hintParts.join('\n')) : '';
                    var tmo = (typeof res.timeout_seconds !== 'undefined') ? res.timeout_seconds : '';
                    alert('Ошибка генерации (опрос статуса).' + hint
                        + (tmo !== '' ? '\n\nТаймаут сервера: ' + tmo + ' с (env AI_GENERATION_TIMEOUT / config ai.generation.timeout_seconds).' : '')
                        + '\n\nСм. также laravel.log и таблицу failed_jobs.');
                    $('#btn-generate-ai').prop('disabled', false);
                    $('#ai-loader').hide();
                    return;
                }
                if (res && res.is_ready === true && res.status === 'success') {
                    setAllIndicatorsState('success');
                    $('#btn-generate-ai').prop('disabled', false);
                    $('#ai-loader').hide();
                    clearInterval(pollTimer);
                }
            },
            error: function (xhr) {
                clearInterval(pollTimer);
                setAllIndicatorsState('error');
                alert('Ошибка проверки статуса: ' + xhr.status);
                $('#btn-generate-ai').prop('disabled', false);
                $('#ai-loader').hide();
            }
        });
    }, 5000); // интервал опроса статуса
})
                // ОТВЕТ С ОШИБКОЙ (например, валидация, 500, проблемы с Redis/OpenAI и т.п.)
                .fail(function (xhr) {
                    setAllIndicatorsState('error');
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Ошибка сервера или лимиты API';
                    alert('Произошла ошибка: ' + msg);
                    $btn.prop('disabled', false);
                    $loader.hide();
                });
        });
    });
 /*
написано так загружай загрузил файл, он отправился на сервер, Потом он вернулся. Уже переработано, если оно в доке или или даже не в доке обычный текст. И потом он загружается снова уже переработаны.  после чего и после чего оно отправляется уже в другой контроллер на обработку
в итоге на мервер предется сырьё, и результат + айди страницы

*/   
    </script>
<script>
$(function () {
    var wpButtonHtml = '<i class="fab fa-wordpress"></i> WordPress';

    $('#btn-wp-publish').on('click', function () {
        var $btn = $(this);

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');

        $.ajax({
            url: '{{ route("admin.products.publish_wordpress", ["product" => $product->id], false) }}',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .done(function (response) {
                alert(response.message || 'Publish queued.');
                $btn.html('<i class="fas fa-check"></i> Queued');
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : 'Unknown error';
                alert('Error: ' + msg);
                $btn.prop('disabled', false).html(wpButtonHtml);
            });
    });
});
</script>

    @include('admin.partials.slug-auto-sync')
@endsection
