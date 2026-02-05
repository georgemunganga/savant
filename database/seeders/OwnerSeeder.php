<?php

namespace Database\Seeders;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Database\Seeder;

class OwnerSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $ownerUser = User::where('email', 'owner@gmail.com')->first();
        if (!$ownerUser) {
            return;
        }

        Owner::firstOrCreate(
            ['user_id' => $ownerUser->id],
            []
        );
    }
}
