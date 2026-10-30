<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 機械検査 (b) レコードを書く口の呼び手の 2 つの一覧(docs の 設計/業務ツール/機械検査.md。一覧と規則 = tests/Architecture/Policy/RecordWriters.php)の自己証明。
 * 登録・更新の口: 一覧の外(AI の処理・AI の書き込みの口とその名前空間のほかのクラス・業務ツールのモジュール・一覧の名前空間と名前の頭が同じだけの別の名前空間・
 * 入れ物の内部・database/ の下の seeder でないファイル。名前空間を持たない無名クラスのマイグレーションを含む)から CreateRecord・UpdateRecord に依存すると、
 * 規則で赤になる(抑制の注釈を付けても消えない)。一覧の場所(入れ物の入口・ワークフローの業務処理とその配下・seeder)からの依存は通る。
 * 判定しない削除の口: 一覧の外(業務ツールのモジュール・入れ物の内部)から DeleteRecordUnchecked に依存すると赤になり(抑制の注釈を付けても消えない)、
 * 一覧の場所(ワークフローの業務処理)からの依存は通る。
 * 判定しない更新・読み取りの口: 一覧の外(AI の書き込みの口と同じ名前空間のほかのクラス・もう一方の口・登録・更新の一覧の場所・
 * 業務ツールのモジュール・入れ物の内部)から UpdateRecordUnchecked・RecordShowUnchecked に依存すると赤になり(抑制の注釈を付けても
 * 消えない)、一覧の場所(更新 = AI の書き込みの口、読み取り = AI の読む口)からの依存は通る。
 * AI の依頼の口と音声の依頼の部品: 一覧に無い業務処理(議事録の種類・入口の判定の部品・コマンド・Ai の内部)から RequestPrompt・RequestTranscription
 * に、依頼の口の一覧の場所(完了の受け手)・入口のコントローラから MeetingAudioRequest に依存すると赤になり、一覧の場所(作成・再作成・
 * 完了の受け手・音声の依頼の部品)と、文だけを RunRequestMessages から使う入口の判定の部品は通る。
 * 業務モジュールを非表示にする口: 一覧の外(コンテンツを作る業務処理と同じ名前空間のほかのクラス・seeder・入れ物の内部)から
 * HideRecordModuleUnchecked に依存すると赤になり(抑制の注釈を付けても消えない)、一覧の場所(コンテンツを作る業務処理)からの依存は通る。
 * fixture は通常の設定のまま(一覧は差し替えない)、走査ルートだけを fixture に差し替える。
 */
final class RecordWritersSelfProofTest extends PhpStanSelfProofTestCase
{
    private const RULE = 'phpat.recordWriterDependencies';

    private const UNCHECKED_DELETE_RULE = 'phpat.uncheckedDeleteDependencies';

    private const UNCHECKED_UPDATE_RULE = 'phpat.uncheckedUpdateDependencies';

    private const UNCHECKED_SHOW_RULE = 'phpat.uncheckedShowDependencies';

    private const REQUEST_RULE = 'phpat.runRequestDependencies';

    private const AUDIO_REQUEST_RULE = 'phpat.meetingAudioRequestDependencies';

    private const HIDE_MODULE_RULE = 'phpat.hideModuleDependencies';

    /**
     * @return array<string, array{string, int}>
     */
    public static function violations(): array
    {
        return [
            'AI の処理が CreateRecord を呼ぶ' => ['Actions/Ai/CreatesDirectly.php', 16],
            'AI の書き込みの口と同じ名前空間のほかのクラス' => ['Actions/Ai/Api/WritesBesideTheWriter.php', 12],
            'AI の書き込みの口(判定する更新の一覧から外した)' => ['Actions/Ai/Api/WriteRecordFromRun.php', 13],
            '一覧の名前空間と名前の頭が同じだけの別の名前空間' => ['Actions/WorkflowLookalike/BypassesList.php', 12],
            '業務ツールのモジュールが UpdateRecord に依存する' => ['Actions/Meeting/UpdatesDirectly.php', 12],
            '抑制の注釈を付けた入れ物の内部(消せない)' => ['Actions/Records/SuppressedCreates.php', 13],
            'database/ の下の seeder でないファイル(factory)' => ['database/factories/CreatesRecordFactory.php', 12],
            '無名クラスのマイグレーション' => ['database/migrations/2026_01_01_000000_creates_records.php', 11],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function uncheckedDeleteViolations(): array
    {
        return [
            '業務ツールのモジュールが DeleteRecordUnchecked に依存する' => ['Actions/Meeting/DeletesUnchecked.php', 12],
            '抑制の注釈を付けた入れ物の内部(消せない)' => ['Actions/Records/SuppressedUncheckedDelete.php', 13],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function uncheckedUpdateViolations(): array
    {
        return [
            'AI の書き込みの口と同じ名前空間のほかのクラス' => ['Actions/Ai/Api/UpdatesBesideTheWriter.php', 12],
            'AI の読む口' => ['Actions/Ai/Api/ReadRecordForRun.php', 12],
            '登録・更新の一覧の場所(ワークフロー)' => ['Actions/Workflow/UpdatesUnchecked.php', 12],
            '抑制の注釈を付けた入れ物の内部(消せない)' => ['Actions/Records/SuppressedUncheckedUpdate.php', 13],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function uncheckedShowViolations(): array
    {
        return [
            'AI の書き込みの口' => ['Actions/Ai/Api/WriteRecordFromRun.php', 13],
            '業務ツールのモジュールが読む口を迂回する' => ['Actions/Meeting/ShowsUnchecked.php', 12],
            '抑制の注釈を付けた入れ物の内部(消せない)' => ['Queries/Records/SuppressedUncheckedShow.php', 13],
        ];
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function requestViolations(): array
    {
        return [
            '一覧に無い業務処理(議事録の種類)' => [self::REQUEST_RULE, 'Actions/Meeting/MeetingMinutesKind.php', 12],
            '入口の判定の部品' => [self::REQUEST_RULE, 'Actions/Meeting/MeetingRequestGate.php', 12],
            '入口を持たない呼び手(コマンド)' => [self::REQUEST_RULE, 'Console/Commands/RequestsFromCommand.php', 12],
            '抑制の注釈を付けた Ai の内部(消せない)' => [self::REQUEST_RULE, 'Actions/Ai/SuppressedRequest.php', 13],
            '依頼の口の一覧の場所(完了の受け手)が部品に依存する' => [
                self::AUDIO_REQUEST_RULE,
                'Actions/Meeting/MeetingTranscriptionKind.php',
                12,
            ],
            '入口のコントローラが部品に依存する' => [self::AUDIO_REQUEST_RULE, 'Http/Controllers/Web/Meeting/UsesAudioRequest.php', 12],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function hideModuleViolations(): array
    {
        return [
            'コンテンツを作る業務処理と同じ名前空間のほかのクラス' => ['Actions/Workflow/HidesBesideTheContent.php', 12],
            '登録・更新の一覧の場所(seeder)' => ['database/seeders/HidesModuleSeeder.php', 13],
            '抑制の注釈を付けた入れ物の内部(消せない)' => ['Actions/Records/SuppressedHide.php', 13],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_dependency_on_the_record_writers_outside_the_list(string $file, int $line): void
    {
        $this->assertDetects(self::RULE, 'RecordWriters/violating/'.$file, $line, self::analyse('RecordWriters/violating'));
    }

    #[DataProvider('uncheckedDeleteViolations')]
    public function test_detects_a_dependency_on_the_unchecked_delete_outside_the_list(string $file, int $line): void
    {
        $analysis = self::analyse('RecordWriters/violating');

        $this->assertDetects(self::UNCHECKED_DELETE_RULE, 'RecordWriters/violating/'.$file, $line, $analysis);
    }

    #[DataProvider('uncheckedUpdateViolations')]
    public function test_detects_a_dependency_on_the_unchecked_update_outside_the_list(string $file, int $line): void
    {
        $analysis = self::analyse('RecordWriters/violating');

        $this->assertDetects(self::UNCHECKED_UPDATE_RULE, 'RecordWriters/violating/'.$file, $line, $analysis);
    }

    #[DataProvider('uncheckedShowViolations')]
    public function test_detects_a_dependency_on_the_unchecked_show_outside_the_list(string $file, int $line): void
    {
        $analysis = self::analyse('RecordWriters/violating');

        $this->assertDetects(self::UNCHECKED_SHOW_RULE, 'RecordWriters/violating/'.$file, $line, $analysis);
    }

    #[DataProvider('requestViolations')]
    public function test_detects_a_dependency_on_the_run_requests_outside_the_list(
        string $rule,
        string $file,
        int $line,
    ): void {
        $this->assertDetects($rule, 'RecordWriters/violating/'.$file, $line, self::analyse('RecordWriters/violating'));
    }

    #[DataProvider('hideModuleViolations')]
    public function test_detects_a_dependency_on_the_hide_module_outside_the_list(string $file, int $line): void
    {
        $analysis = self::analyse('RecordWriters/violating');

        $this->assertDetects(self::HIDE_MODULE_RULE, 'RecordWriters/violating/'.$file, $line, $analysis);
    }

    public function test_passes_the_callers_in_the_list(): void
    {
        $this->assertClean(self::analyse('RecordWriters/passing'));
    }
}
