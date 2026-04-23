<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryDescription;
use App\Models\Language;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductDescription;
use App\Models\Prompt;
use App\Models\PromptCategory;
use App\Models\PromptCategoryDescription;
use App\Models\PromptDescription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    public function index(): Response
    {
        $manufacturerId = 1;

        $one = DB::table('prompt_categories')
            ->where('ai_field', 'ai_text_about_the_country')
            ->where('manufacturer_id', $manufacturerId)
            ->value('row_data');

        $sourceData = DB::table('countries_data')
            ->where('manufacturer_id', $manufacturer_id)
            ->first();

        return response(
            $one === null ? '(null — строка не найдена или row_data пусто)' : (string) $one,
            200
        )->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
