<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompt_category_descriptions')) {
            return;
        }

        Schema::create('prompt_category_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_category_id')->constrained('prompt_categories')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['prompt_category_id', 'language_id']);
            $table->unique(['language_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_category_descriptions');
    }
};
