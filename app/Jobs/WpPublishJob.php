<?php

namespace App\Jobs; // Пространство имён — логическая папка, где лежит этот класс

use App\Models\Product; // Модель товара (Eloquent)
use App\Services\WordPressService; // Сервис, который отправляет данные в WordPress
use Illuminate\Bus\Queueable; // Даёт настройки очереди (delay, queue name и т.д.)
use Illuminate\Contracts\Queue\ShouldQueue; // ⚠️ Маркер: "эту задачу выполнять через очередь" Trait = набор готовых методов (функций)
use Illuminate\Foundation\Bus\Dispatchable; // Даёт метод ::dispatch()  - это трейт
use Illuminate\Queue\InteractsWithQueue; // Управление задачей (повторы, удаление и т.д.)
use Illuminate\Queue\SerializesModels; // ⚠️ Магия: модель сохраняется как ID, а не целиком
use Illuminate\Support\Facades\Log; // Логирование

class WpPublishJob implements ShouldQueue // Говорим Laravel: "не выполняй сразу"
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    // я беру 4 готовых модуля и встраиваю их в свой класс
    // Dispatchable → можно вызвать WpPublishJob::dispatch()
    // InteractsWithQueue → можно управлять job во время выполнения
    // Queueable → можно настроить очередь (delay, имя очереди)
    // SerializesModels → модель Product превращается в ID при сохранении в очередь


/*
    В контроллере вызывается:

        WpPublishJob::dispatch($product);

    👉 dispatch создаёт объект:
        new WpPublishJob($product); для вызова конструктора

    👉 при создании объекта автоматически вызывается конструктор

    👉 $product передаётся в конструктор
       и сохраняется в $this->product

    👉 затем объект Job кладётся в очередь
*/

    public function __construct(public Product $product) {}   // отсюда приходит WpPublishJob::dispatch($product);

// WpPublishJob::dispatch($product); WpPublishJob::dispatch($product);

    // Конструктор — сюда приходит Product при dispatch
    // ⚠️ ВАЖНО: реально сохраняется только product->id, а не весь объект
// запуск логики
    public function handle(WordPressService $service): void

    //$service = new WordPressService();
    //$job->handle($service);

    // Метод, который выполнит queue worker (php artisan queue:work)
    // WordPressService автоматически создаётся Laravel (Dependency Injection)
    {
        Log::info('[WpPublishJob] Запуск процесса для товара', [
            'product_id' => $this->product->id
        ]);
        // Лог старта — чтобы видеть, что job реально начался

        // Сервис теперь делает всю работу: сборку, фильтрацию и отправку
        // вызываем метод  publish
        $service->publish($this->product);
        // Здесь происходит:
        // 1. Сбор данных товара
        // 2. Подготовка JSON
        // 3. HTTP запрос в WordPress

        Log::info('[WpPublishJob] Успешно завершил работу', [
            'product_id' => $this->product->id
        ]);
        // Лог завершения — значит всё прошло без ошибок
    }
}