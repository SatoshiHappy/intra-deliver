<?php

namespace Database\Seeders\SeederFixture;

use Database\Factories\Master\UserFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;

/**
 * seeder の run() の引数で factory と Faker を受け取る(コンテナが解決して渡すので、本番の --no-dev で動かない)
 */
final class ReceivesFactories extends Seeder
{
    public function run(
        Generator $faker,
        UserFactory $factory,
    ): void {
        $faker->name();
        $factory->create();
    }
}
