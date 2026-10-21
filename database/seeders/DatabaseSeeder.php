<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database(docs の 設計/メニュー/初期データ.md 4 節の順: 共通マスタ → 認可 → 共通マスタのルート → メニュー → 今の利用者 → 業務データの入れ物 → ワークフロー)。
     * NoticeSeeder = 通知のルートのリソースとポリシー(設計/ワークフロー/案件の運用/認可と一覧.md の 4 節)。
     * BuiltinSeeder = 組み込みの業務モジュール(業務モジュールのセットと、参照先のプロジェクトを要するので業務データの入れ物の後 =
     * 設計/業務ツール/モジュール境界.md の「組み込みの業務モジュールの置き場」)。
     * MeetingSeeder = 会議の作成・再作成のルートのリソースとポリシー(設計/業務ツール/会議/処理.md の 3.2・3.7)。
     * MeetingSampleSeeder = 見本の会議(設計/業務ツール/会議/公開デモと画面.md の 8.4)。ほかの初期データの id をずらさないよう最後に入れる。
     */
    public function run(): void
    {
        SeedingDates::fixSeedingDay();
        $this->call([
            CommonMasterSeeder::class,
            AuthzSeeder::class,
            CommonMasterRouteSeeder::class,
            MenuSeeder::class,
            AccountSeeder::class,
            RecordsSeeder::class,
            BuiltinSeeder::class,
            MeetingSeeder::class,
            NoticeSeeder::class,
            WorkflowSeeder::class,
            MeetingSampleSeeder::class,
        ]);
    }
}
