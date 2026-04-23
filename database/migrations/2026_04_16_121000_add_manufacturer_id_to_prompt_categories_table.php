<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('prompt_categories', 'manufacturer_id')) {
            return;
        }

        Schema::table('prompt_categories', function (Blueprint $table) {
            $table->foreignId('manufacturer_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('manufacturers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prompt_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manufacturer_id');
        });
    }
};
