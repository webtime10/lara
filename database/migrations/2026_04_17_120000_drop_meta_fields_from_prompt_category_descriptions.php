<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prompt_category_descriptions')) {
            return;
        }

        Schema::table('prompt_category_descriptions', function (Blueprint $table) {
            if (Schema::hasColumn('prompt_category_descriptions', 'meta_keyword')) {
                $table->dropColumn('meta_keyword');
            }
            if (Schema::hasColumn('prompt_category_descriptions', 'meta_description')) {
                $table->dropColumn('meta_description');
            }
            if (Schema::hasColumn('prompt_category_descriptions', 'meta_title')) {
                $table->dropColumn('meta_title');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('prompt_category_descriptions')) {
            return;
        }

        Schema::table('prompt_category_descriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('prompt_category_descriptions', 'meta_title')) {
                $table->string('meta_title', 255)->nullable();
            }
            if (! Schema::hasColumn('prompt_category_descriptions', 'meta_description')) {
                $table->string('meta_description', 255)->nullable();
            }
            if (! Schema::hasColumn('prompt_category_descriptions', 'meta_keyword')) {
                $table->string('meta_keyword', 255)->nullable();
            }
        });
    }
};
