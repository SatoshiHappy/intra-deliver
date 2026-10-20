<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 共通マスタの初期データ(架空の「株式会社デリバー商事」)。空の DB に 1 回入れる前提(作り直し・冪等は公開デモのデータの戻し方を決める単位で作る)。
 * すべて業務処理を通して入れ、factory と Faker を使わない(本番の --no-dev でも動く。PHPStan の自作規則が止める)。
 */
class CommonMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OrganizationSeeder::class,
            CompanyPostSeeder::class,
            UserSeeder::class,
            MembershipSeeder::class,
            RoleSeeder::class,
        ]);
    }
}
