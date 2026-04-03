<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Основная строка товара (оптимизировано под 100k+ товаров).
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // Индексы для мгновенного поиска по модели и SKU
            $table->string('model', 64)->index();
            $table->string('sku', 64)->nullable()->index();
            
            $table->string('upc', 12)->nullable();
            $table->string('ean', 14)->nullable();
            $table->string('jan', 13)->nullable();
            $table->string('isbn', 17)->nullable();
            $table->string('mpn', 64)->nullable();
            $table->string('location', 128)->nullable();
            $table->integer('quantity')->default(0);
            $table->unsignedBigInteger('stock_status_id')->nullable();
            $table->string('image', 255)->nullable();
            
            // Внешний ключ на производителя
            $table->foreignId('manufacturer_id')->nullable()->constrained('manufacturers')->nullOnDelete();
            
            $table->boolean('shipping')->default(true);
            $table->decimal('price', 15, 4)->default(0)->index(); // Индекс для сортировки по цене
            $table->integer('points')->default(0);
            $table->unsignedBigInteger('tax_class_id')->nullable();
            $table->date('date_available')->nullable();
            $table->decimal('weight', 15, 8)->default(0);
            $table->unsignedInteger('weight_class_id')->default(0);
            $table->decimal('length', 15, 8)->default(0);
            $table->decimal('width', 15, 8)->default(0);
            $table->decimal('height', 15, 8)->default(0);
            $table->unsignedInteger('length_class_id')->default(0);
            $table->boolean('subtract')->default(true);
            $table->unsignedInteger('minimum')->default(1);
            $table->unsignedInteger('sort_order')->default(0)->index(); // Индекс для сортировки в каталоге
            $table->boolean('status')->default(true)->index(); // Индекс для фильтрации активных товаров
            $table->unsignedInteger('viewed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};