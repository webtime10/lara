<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('prompt_categories', 'row_data')) {
                $table->string('row_data')->nullable()->after('ai_field');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prompt_categories', function (Blueprint $table) {
            if (Schema::hasColumn('prompt_categories', 'row_data')) {
                $table->dropColumn('row_data');
            }
        });
    }
};
