<?php

namespace Tests\Feature\Reset;

use App\Queries\Reset\Api\ResetSeeder;

/** seeder の名前の口のテスト用の実装(コンストラクタで渡した名前を返す。テストが束縛して登録する) */
class TestResetSeeder implements ResetSeeder
{
    public function __construct(private string $name = WritesFileSeeder::class) {}

    public function seederName(): string
    {
        return $this->name;
    }
}
