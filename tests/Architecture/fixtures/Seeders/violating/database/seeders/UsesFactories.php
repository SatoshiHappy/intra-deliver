<?php

namespace Database\Seeders\SeederFixture;

use App\Models\Master\User;
use Database\Factories\Master\UserFactory;
use Illuminate\Database\Seeder;

/**
 * seeder から factory と Faker を使う(本番の --no-dev で動かない)
 */
final class UsesFactories extends Seeder
{
    public function run(): void
    {
        User::factory()->create();
        UserFactory::new()->create();
        fake()->name();
        \Faker\Factory::create()->name();
        // @phpstan-ignore forbiddenExpressionType
        User::factory()->create();
    }
}
