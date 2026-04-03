<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Дерево путей категории (как category_path в OpenCart 3).
     */
    public function up(): void
    {
        Schema::create('category_paths', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('path_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('level');

            $table->primary(['category_id', 'path_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_paths');
    }
};
