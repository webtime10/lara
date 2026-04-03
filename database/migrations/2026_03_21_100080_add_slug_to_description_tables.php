<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slug для маршрутов Laravel (уникален в паре с language_id).
     */
    public function up(): void
    {
        if (Schema::hasTable('category_descriptions') && ! Schema::hasColumn('category_descriptions', 'slug')) {
            Schema::table('category_descriptions', function (Blueprint $table) {
                $table->string('slug', 255)->nullable()->after('name');
            });
            Schema::table('category_descriptions', function (Blueprint $table) {
                $table->unique(['language_id', 'slug']);
            });
        }

        if (Schema::hasTable('product_descriptions') && ! Schema::hasColumn('product_descriptions', 'slug')) {
            Schema::table('product_descriptions', function (Blueprint $table) {
                $table->string('slug', 255)->nullable()->after('name');
            });
            Schema::table('product_descriptions', function (Blueprint $table) {
                $table->unique(['language_id', 'slug']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('category_descriptions') && Schema::hasColumn('category_descriptions', 'slug')) {
            Schema::table('category_descriptions', function (Blueprint $table) {
                $table->dropUnique(['language_id', 'slug']);
                $table->dropColumn('slug');
            });
        }

        if (Schema::hasTable('product_descriptions') && Schema::hasColumn('product_descriptions', 'slug')) {
            Schema::table('product_descriptions', function (Blueprint $table) {
                $table->dropUnique(['language_id', 'slug']);
                $table->dropColumn('slug');
            });
        }
    }
};
