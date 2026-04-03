<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_descriptions', function (Blueprint $table) {
            $table->longText('ai_reviews_from_tourists')->nullable()->after('ai_text_about_the_country');
        });
    }

    public function down(): void
    {
        Schema::table('product_descriptions', function (Blueprint $table) {
            $table->dropColumn('ai_reviews_from_tourists');
        });
    }
};
