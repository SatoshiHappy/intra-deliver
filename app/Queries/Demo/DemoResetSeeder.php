<?php

namespace App\Queries\Demo;

use App\Queries\Reset\Api\ResetSeeder;

/**
 * 毎日の初期化と初期データだけを入れるコマンドで流す seeder の名前(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3 の 5。口と保証は Reset の
 * {@see ResetSeeder} = 設計/公開の配備/毎日の初期化.md 7 節)。
 */
class DemoResetSeeder implements ResetSeeder
{
    public function seederName(): string
    {
        return 'DemoSeeder';
    }
}
