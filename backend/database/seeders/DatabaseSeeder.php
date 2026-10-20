<?php

namespace Database\Seeders;

use App\Services\InstallService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(InstallService::class)->indexes();
        app(InstallService::class)->catalog();
    }
}
