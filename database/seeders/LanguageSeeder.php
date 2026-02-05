<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        Language::firstOrCreate(
            ['code' => 'en'],
            [
                'name' => 'English',
                'icon' => null,
                'rtl' => 0,
                'status' => 1,
                'default' => 1,
                'font_id' => null,
            ]
        );
    }
}
