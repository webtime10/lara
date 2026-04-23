<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('prompt_categories', 'stage_1_extraction')) {
            return;
        }

        Schema::table('prompt_categories', function (Blueprint $table) {
            $table->longText('stage_1_extraction')->nullable()->after('manufacturer_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('prompt_categories', 'stage_1_extraction')) {
            return;
        }

        Schema::table('prompt_categories', function (Blueprint $table) {
            $table->dropColumn('stage_1_extraction');
        });
    }
};
