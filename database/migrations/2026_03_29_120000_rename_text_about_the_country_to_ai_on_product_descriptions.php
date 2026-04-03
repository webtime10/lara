<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_descriptions')) {
            return;
        }
        if (! Schema::hasColumn('product_descriptions', 'text_about_the_country')) {
            return;
        }
        if (Schema::hasColumn('product_descriptions', 'ai_text_about_the_country')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `product_descriptions` CHANGE `text_about_the_country` `ai_text_about_the_country` LONGTEXT NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement('ALTER TABLE product_descriptions RENAME COLUMN text_about_the_country TO ai_text_about_the_country');
        } else {
            Schema::table('product_descriptions', function (Blueprint $table) {
                $table->renameColumn('text_about_the_country', 'ai_text_about_the_country');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_descriptions')) {
            return;
        }
        if (! Schema::hasColumn('product_descriptions', 'ai_text_about_the_country')) {
            return;
        }
        if (Schema::hasColumn('product_descriptions', 'text_about_the_country')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `product_descriptions` CHANGE `ai_text_about_the_country` `text_about_the_country` LONGTEXT NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement('ALTER TABLE product_descriptions RENAME COLUMN ai_text_about_the_country TO text_about_the_country');
        } else {
            Schema::table('product_descriptions', function (Blueprint $table) {
                $table->renameColumn('ai_text_about_the_country', 'text_about_the_country');
            });
        }
    }
};
