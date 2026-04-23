<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompts')) {
            return;
        }

        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_category_id')->nullable()->constrained('prompt_categories')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompts');
    }
};
