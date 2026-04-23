<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('prompt_categories')) {
            return;
        }

        Schema::create('prompt_categories', function (Blueprint $table) {
            $table->id();
            $table->string('image', 255)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('prompt_categories')->nullOnDelete();
            $table->boolean('top')->default(false);
            $table->unsignedInteger('column')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_categories');
    }
};
