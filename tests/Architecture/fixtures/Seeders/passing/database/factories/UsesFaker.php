<?php

namespace Database\Factories\SeederFixture;

/**
 * factory の置き場では Faker を使える(規則は seeder だけに掛かる)
 */
final class UsesFaker
{
    public function name(): string
    {
        return fake()->name();
    }
}
