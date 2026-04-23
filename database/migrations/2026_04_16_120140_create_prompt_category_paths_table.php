<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompt_category_paths')) {
            return;
        }

        Schema::create('prompt_category_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_category_id')->constrained('prompt_categories')->cascadeOnDelete();
            $table->foreignId('path_id')->constrained('prompt_categories')->cascadeOnDelete();
            $table->unsignedInteger('level');

            $table->unique(['prompt_category_id', 'path_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_category_paths');
    }
};
