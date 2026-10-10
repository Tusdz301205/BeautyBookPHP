<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate([
            'email' => 'foundation@beautybook.local',
        ], [
            'name' => 'BeautyBook Foundation',
            'password' => 'beautybook-local-only',
        ]);

        $this->call(IdentityDemoSeeder::class);
    }
}
