<?php

namespace Tests\Support;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 各テストを TX で包み、終わりに巻き戻す基底クラス(Feature 用。Laravel の RefreshDatabase。
 * 初期データの入れ方 = docs の 設計/コード構成/検査/一括検査の段.md 6.5 の「並列(T)」)。
 * #[Seeder] にするのは、初期データを TX の外で 1 回だけ入れ、テストごとの巻き戻しで消さないため。
 * 初期データの日付は流す日からの相対で入るが、phpunit.xml が流す日を基準の日に固定する(ずれが 0)ので、期待には seeder の日付の定数をそのまま使う。
 * 時刻(created_at など)は、時計を合わせて入れる見本の行(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.2)は流す日からの相対で入り、
 * ほかの行は実際の時計で入る(setTestNow より前)ので、見本でない行の時刻に依る検証を書かない。
 * 流用元 = chatpinet_2 の同名のクラス(既定テナントの文脈の開始を削った)。
 */
#[Seeder(DatabaseSeeder::class)]
abstract class TransactionalTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * migrate:fresh と TX の開始の前に、接続先がテスト DB であることを確かめる。
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->ensureTestDatabase();
    }
}
