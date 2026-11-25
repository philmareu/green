<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Loads the demo: sample Books, banks, transactions and recurring items
     * under the login test@example.com / password. See `DemoSeeder`.
     *
     * This seeder must not use `WithoutModelEvents`: it would switch model
     * events off for the demo too, and categorizing and matching run from them.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
