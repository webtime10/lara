<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('prompt_categories', 'ai_field')) {
                $table->string('ai_field', 64)->nullable()->after('manufacturer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('prompt_categories', function (Blueprint $table) {
            if (Schema::hasColumn('prompt_categories', 'ai_field')) {
                $table->dropColumn('ai_field');
            }
        });
    }
};
