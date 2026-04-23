<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_descriptions', function (Blueprint $table) {
            $table->longText('ai_faq')->nullable()->after('ai_reviews_from_tourists');
        });
    }

    public function down(): void
    {
        Schema::table('product_descriptions', function (Blueprint $table) {
            $table->dropColumn('ai_faq');
        });
    }
};

