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
            if (! Schema::hasColumn('prompt_category_descriptions', 'stage_2_live')) {
                $table->longText('stage_2_live')->nullable()->after('description');
            }
            if (! Schema::hasColumn('prompt_category_descriptions', 'stage_3_edit')) {
                $table->longText('stage_3_edit')->nullable()->after('stage_2_live');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('prompt_category_descriptions')) {
            return;
        }

        Schema::table('prompt_category_descriptions', function (Blueprint $table) {
            if (Schema::hasColumn('prompt_category_descriptions', 'stage_3_edit')) {
                $table->dropColumn('stage_3_edit');
            }
            if (Schema::hasColumn('prompt_category_descriptions', 'stage_2_live')) {
                $table->dropColumn('stage_2_live');
            }
        });
    }
};
