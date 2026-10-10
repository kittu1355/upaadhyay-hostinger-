<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/** The three faculty segments used across the site. Safe to run repeatedly. */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Schools' => 'schools', 'Intermediate' => 'intermediate', 'Higher Education' => 'higher-education'] as $name => $slug) {
            Category::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
