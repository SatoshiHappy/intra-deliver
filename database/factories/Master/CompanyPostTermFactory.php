<?php

namespace Database\Factories\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPostTerm>
 */
class CompanyPostTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_post_id' => CompanyPost::factory(),
            'start_date' => '2020-01-01',
            'end_date' => null,
            'is_deleted' => false,
        ];
    }
}
