<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * B1 副作用は台帳②の場所だけ(docs の 設計/コード構成/README.md 4 節。PHPStan の自作規則 SideEffectsRule)の自己証明。
 * 台帳②に無い場所(層・層の中のモジュール)と配線のファイルでの副作用が、種類ごとの規則で赤になる(抑制の注釈を付けても消えない)。
 * 台帳②の場所での副作用・読み取り・照合しないディレクトリ(database/)と、起動で HTTP クライアントを作るだけのこと
 * (メソッド名が * の型でも、コンストラクタは明示した型だけ数える)は通る。
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/SideEffects/{violating,passing})をアプリのルートに見立て、
 * 台帳②は fixture 用(同 guardrails_allow.php。HTTP・AI の呼び出し・キューへの投入をモジュール Alpha の業務処理に限る)を使う。
 * 判定する呼び出しは Policy の実物。
 */
final class SideEffectsSelfProofTest extends PhpStanSelfProofTestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function violations(): array
    {
        return [
            '読み取りからモデルの保存' => ['sideEffect.dbWrite', 'app/Queries/Alpha/WritesFromQuery.php', 16],
            '読み取りから Eloquent のビルダの更新' => ['sideEffect.dbWrite', 'app/Queries/Alpha/WritesFromQuery.php', 21],
            '読み取りからクエリビルダの挿入' => ['sideEffect.dbWrite', 'app/Queries/Alpha/WritesFromQuery.php', 26],
            '読み取りから表の定義の変更' => ['sideEffect.dbWrite', 'app/Queries/Alpha/WritesFromQuery.php', 31],
            '入口からモデルの静的呼び出しで作成' => ['sideEffect.dbWrite', 'app/Http/Controllers/Web/Alpha/CreatesFromEntrance.php', 15],
            '共有からトランザクション' => ['sideEffect.dbWrite', 'app/Support/RunsEffects.php', 17],
            '抑制の注釈を付けたモデルの削除(消せない)' => ['sideEffect.dbWrite', 'app/Queries/Alpha/SuppressedWrite.php', 15],
            '入口からジョブの投入(Dispatchable)' => ['sideEffect.queue', 'app/Http/Controllers/Web/Alpha/CreatesFromEntrance.php', 20],
            '入口からジョブの投入(関数)' => ['sideEffect.queue', 'app/Http/Controllers/Web/Alpha/CreatesFromEntrance.php', 21],
            '共有からメールの送信' => ['sideEffect.notification', 'app/Support/RunsEffects.php', 22],
            '共有からストレージへの保存' => ['sideEffect.fileStorage', 'app/Support/RunsEffects.php', 27],
            '共有からファイルの書き込み(関数)' => ['sideEffect.fileStorage', 'app/Support/RunsEffects.php', 28],
            '共有から SplFileObject を開く(new。モードに依らない)' => ['sideEffect.fileStorage', 'app/Support/WritesWithSplFile.php', 15],
            '共有から SplFileObject への書き込み' => ['sideEffect.fileStorage', 'app/Support/WritesWithSplFile.php', 16],
            '共有から SplFileInfo::openFile' => ['sideEffect.fileStorage', 'app/Support/WritesWithSplFile.php', 21],
            '配線のファイルで式に書いたクラス名から SplFileObject を作る' => ['sideEffect.fileStorage', 'routes/files.php', 3],
            '配線のファイルで SplFileObject を継承した匿名クラスを作る' => ['sideEffect.fileStorage', 'routes/files.php', 5],
            '共有からプロセスの起動(ファサード)' => ['sideEffect.process', 'app/Support/RunsEffects.php', 33],
            '共有からプロセスの起動(関数)' => ['sideEffect.process', 'app/Support/RunsEffects.php', 34],
            '共有からプロセスの起動(バッククォート)' => ['sideEffect.process', 'app/Support/RunsEffects.php', 35],
            '台帳②で許していないモジュールの業務処理から HTTP' => ['sideEffect.http', 'app/Actions/Beta/CallsHttp.php', 14],
            '台帳②で許していないモジュールの業務処理からエージェントの呼び出し' => ['sideEffect.ai', 'app/Actions/Beta/CallsAi.php', 17],
            '台帳②で許していないモジュールの業務処理から関数で作ったエージェントの呼び出し' => ['sideEffect.ai', 'app/Actions/Beta/CallsAi.php', 18],
            '台帳②で許していないモジュールの業務処理から生成の依頼' => ['sideEffect.ai', 'app/Actions/Beta/CallsAi.php', 19],
            '台帳②で許していないモジュールの業務処理から接続先を直接呼ぶ(ファサード)' => ['sideEffect.ai', 'app/Actions/Beta/CallsProviderDirectly.php', 20],
            '台帳②で許していないモジュールの業務処理から Gateway を作って呼ぶ' => ['sideEffect.ai', 'app/Actions/Beta/CallsProviderDirectly.php', 21],
            '台帳②で許していないモジュールの業務処理からジョブの投入' => ['sideEffect.queue', 'app/Actions/Beta/DispatchesJob.php', 14],
            '共有から HTTP(get_headers)' => ['sideEffect.http', 'app/Support/WritesWithSplFile.php', 29],
            '配線のファイルから HTTP' => ['sideEffect.http', 'routes/web.php', 6],
        ];
    }

    #[DataProvider('violations')]
    public function test_detects_a_side_effect_outside_the_ledger(string $ruleId, string $file, int $line): void
    {
        $this->assertDetects($ruleId, 'SideEffects/violating/'.$file, $line, self::analyse('SideEffects/violating'));
    }

    public function test_passes_side_effects_in_the_ledger_and_reads(): void
    {
        $this->assertClean(self::analyse('SideEffects/passing'));
    }
}
