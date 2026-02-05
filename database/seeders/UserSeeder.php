<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Admin
        User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'first_name' => 'Mr',
                'last_name' => 'Admin',
                'password' => Hash::make('12345678'),
                'contact_number' => '01951973806',
                'status' => 1,
                'role' => 4,
                'email_verified_at' => now(),
            ]
        );

        // Owner
        $owner = User::firstOrCreate(
            ['email' => 'owner@gmail.com'],
            [
                'first_name' => 'Mr',
                'last_name' => 'Owner',
                'password' => Hash::make('12345678'),
                'contact_number' => '01952973806',
                'status' => 1,
                'role' => 1,
                'email_verified_at' => now(),
            ]
        );

        if ($owner->owner_user_id !== $owner->id) {
            $owner->owner_user_id = $owner->id;
            $owner->save();
        }
    }
}
