<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            LanguageSeeder::class,
            UserSeeder::class,
            OwnerSeeder::class,
            //LanguageSeeder::class,
            //CurrencySeeder::class,
            //MetaSettingSeeder::class,
            //FileManagerSeeder::class,
            //InvoiceTypeSeeder::class,
            //GatewaySeeder::class,
            //SettingSeeder::class,
        ]);
    }
}
