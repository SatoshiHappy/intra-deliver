<?php

namespace Tests\Support;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 各テストを TX で包み、終わりに巻き戻す基底クラス(Feature 用。Laravel の RefreshDatabase。
 * 初期データの入れ方 = docs の 設計/コード構成/検査/一括検査の段.md 6.5 の「並列(T)」)。
 * #[Seeder] にするのは、初期データを TX の外で 1 回だけ入れ、テストごとの巻き戻しで消さないため。
 * 初期データの日付は流す日からの相対で入るが、phpunit.xml が流す日を基準の日に固定する(ずれが 0)ので、期待には seeder の日付の定数をそのまま使う。
 * 時刻(created_at など)は、時計を合わせて入れる見本の行(docs の 設計/ワークフロー/案件の運用/初期データ.md 1.2)は流す日からの相対で入り、
 * ほかの行は実際の時計で入る(setTestNow より前)ので、見本でない行の時刻に依る検証を書かない。
 * 初期データの添付の実体は TX で巻き戻らない。本物の置き場に書くと、テストの順で消え、流すたびに増えるので、初期データを入れる回はテストの置き場に書く
 * ({@see self::fakeSeedingDisk()})。
 * 流用元 = chatpinet_2 の同名のクラス(既定テナントの文脈の開始を削った)。
 */
#[Seeder(DatabaseSeeder::class)]
abstract class TransactionalTestCase extends TestCase
{
    use RefreshDatabase;

    /** 初期データを入れる回に実体の置き場を偽装したか */
    private bool $seedingDiskFaked = false;

    /**
     * migrate:fresh と TX の開始の前に、接続先がテスト DB であることを確かめる。初期データを入れる回(プロセスの最初と、前のテストが TX を外した後)は
     * 実体の置き場を偽装する。
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->ensureTestDatabase();

        if (! RefreshDatabaseState::$migrated) {
            $this->fakeSeedingDisk();
            $this->seedingDiskFaked = true;
        }
    }

    /**
     * 初期データを入れた回は、テストの本体を本物の置き場に戻す。
     */
    protected function afterRefreshingDatabase(): void
    {
        if ($this->seedingDiskFaked) {
            Storage::forgetDisk('local');
        }
    }

    /**
     * 初期データを流す前に、実体の置き場(local)をテストの置き場にする。置き場は偽装を作る時に空になり、テストの終わりに消す。
     */
    protected function fakeSeedingDisk(): void
    {
        $root = Storage::fake('local')->path('');
        // 自分の置き場(トークンつき)だけを消す。親の disks は並べて走るほかのテストのプロセスと共有なので触れない
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($root));
    }
}
