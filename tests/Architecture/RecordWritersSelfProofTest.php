<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 機械検査 (b) レコードを書く口の呼び手の一覧(docs の 設計/業務ツール/機械検査.md。一覧と規則 = tests/Architecture/Policy/RecordWriters.php)の自己証明。
 * 一覧の外(AI の処理・AI の書き込みの口と同じ名前空間のほかのクラス・業務ツールのモジュール・一覧の名前空間と名前の頭が同じだけの別の名前空間・
 * 入れ物の内部・database/ の下の seeder でないファイル。名前空間を持たない無名クラスのマイグレーションを含む)から CreateRecord・UpdateRecord に依存すると、
 * 規則で赤になる(抑制の注釈を付けても消えない)。一覧の場所(入れ物の入口・ワークフローの業務処理とその配下・AI の書き込みの口・seeder)からの依存は通る。
 * fixture は通常の設定のまま(一覧は差し替えない)、走査ルートだけを fixture に差し替える。
 */
final class RecordWritersSelfProofTest extends PhpStanSelfProofTestCase
{
    private const RULE = 'phpat.recordWriterDependencies';

    /**
     * @return array<string, array{string, int}>
     */
    public static function violations(): array
    {
        return [
            'AI の処理が CreateRecord を呼ぶ' => ['Actions/Ai/CreatesDirectly.php', 16],
            'AI の書き込みの口と同じ名前空間のほかのクラス' => ['Actions/Ai/Api/WritesBesideTheWriter.php', 12],
            '一覧の名前空間と名前の頭が同じだけの別の名前空間' => ['Actions/WorkflowLookalike/BypassesList.php', 12],
            '業務ツールのモジュールが UpdateRecord に依存する' => ['Actions/Meeting/UpdatesDirectly.php', 12],
            '抑制の注釈を付けた入れ物の内部(消せない)' => ['Actions/Records/SuppressedCreates.php', 13],
            'database/ の下の seeder でないファイル(factory)' => ['database/factories/CreatesRecordFactory.php', 12],
            '無名クラスのマイグレーション' => ['database/migrations/2026_01_01_000000_creates_records.php', 11],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_dependency_on_the_record_writers_outside_the_list(string $file, int $line): void
    {
        $this->assertDetects(self::RULE, 'RecordWriters/violating/'.$file, $line, self::analyse('RecordWriters/violating'));
    }

    public function test_passes_the_callers_in_the_list(): void
    {
        $this->assertClean(self::analyse('RecordWriters/passing'));
    }
}
