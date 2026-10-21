<?php

namespace Tests\Architecture\Policy;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;

/**
 * モジュールの依存の宣言(docs の 設計/コード構成/README.md 3 節)と、モジュールのサブ名前空間を持つ置き場(同 1 節・5 節)。
 *
 * 宣言 = モジュールを依存の向きに並べ、各モジュールに依存してよいモジュールを書いた一覧。依存先に書けるのは一覧で自分より前にあるモジュールだけ
 * (照合 = tests/Architecture/ModulesTest.php)。宣言から、モジュールどうしの依存の規則({@see LayerRules::moduleDependencies()})と、
 * 置き場の直下に置けるディレクトリ({@see CodeLayout::placements()})を作る。
 * モジュールを足すときは、一覧の並びの基準(コード構成 3 節)に従った位置に足す。
 */
final class Modules
{
    /**
     * 宣言(一覧の順 = 依存の向き。モジュール名 → 依存してよいモジュール)。
     * Master = 土台の共通マスタ(ロケール・会社・組織セット・組織・役職・ユーザー・所属)。
     * Authz = 土台のロール・認可(付与と対象者がユーザー・組織・役職を指すので Master に依存する)。
     * Menu = 土台のメニュー(トップページはメニューの入口として置く。メニューは認可の最初の利用者なので Authz の後。ロケールの表を指し、
     * 認可の読み取りと業務処理を呼ぶので Master・Authz に依存する = docs の 設計/メニュー/認可との結び付け.md 2.1)。
     * Account = 土台の今の利用者(ログイン・ログアウト・カレント組織の切替・共有。ユーザーと所属を読むので Master に依存する。
     * どの土台からも使われないので後ろに置く = docs の 設計/デモユーザー切替/今の利用者.md 5.1)。
     * Demo = 取り外せるデモユーザー切替(既定オフ。ホームの節の口を実装するので Menu に、守るルートの口を実装するので Authz に依存する。
     * どのモジュールも依存先に書かない = docs の 設計/デモユーザー切替/デモの取り付け.md 6.1、設計/ワークフロー/申請の周辺/README.md の「コードのモジュール」)。
     * Notice = 画面内通知(通知の行・自分宛の一覧と既読・行の移り先を答える口。宛先と起こした人の名前をユーザーから引くので Master に依存する。
     * ワークフローと業務データの入れ物が送り手と移り先の実装になる基盤なので、それらより前に置く = docs の 設計/ワークフロー/案件の運用/通知.md の 1 節)。
     * Upload = アップロードの実体と合計の台帳(持ち主を知らない土台なので、上げた人をユーザーから引く Master だけに依存する。
     * Records・Workflow・業務ツールが添付を持つ基盤なので、使う最初のモジュール Records の前に置く = docs の
     * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 1 節)。
     * Records = 業務データの入れ物(業務モジュール・項目・規則・レコード。参照の項目がユーザー・組織を指し、定義の書き込みが共通マスタの勧告ロックを使い、
     * 業務モジュールの認可のリソース・ポリシーを作って判定し、メニューのアイテムを足すので Master・Authz・Menu に依存する。後の段の複数の製品から
     * 使われる基盤なので、土台(取り外せるモジュールを含む)の直後に置く = docs の 設計/業務データの入れ物/README.md の「コードのモジュール」。
     * 添付の台帳の行を指すので Upload にも依存する = 設計/業務データの入れ物/レコードの共通機能/README.md の「コードのモジュール」)。
     * Ai = LLM の呼び出し口・AI の実行の記録・費用の上限・AI の書き込みの口(依頼者の表示名と有効の確かめに Master、閲覧と書き込みの判定に Authz・Records を使う。
     * ワークフローからも AI を使えるよう Workflow より前、使う基盤の Records の後に置く = docs の 設計/業務ツール/モジュール境界.md の「一覧の並び」・設計/AI/README.md)。
     * Workflow = ワークフロー(名前・対象者の判定・ホームの節・申請フォームのレコードを使うので Master・Authz・Menu・Records に依存し、使う基盤の後に置く = docs の 設計/ワークフロー/README.md の「コードのモジュール」。
     * 申請の一時保存の添付が台帳の行を指すので Upload にも依存する = 設計/業務データの入れ物/レコードの共通機能/README.md の「コードのモジュール」。
     * 案件の操作の通知を送り、案件の移り先を答えるので Notice にも依存する = 設計/ワークフロー/案件の運用/通知.md の 1 節)。
     * 並び = 土台と基盤を先頭に、土台どうしは依存の向き(docs の 設計/共通マスタ/README.md のモジュールの割り当て)。
     *
     * @var array<string, list<string>>
     */
    public const DECLARATION = [
        'Master' => [],
        'Authz' => ['Master'],
        'Menu' => ['Master', 'Authz'],
        'Account' => ['Master'],
        'Demo' => ['Master', 'Authz', 'Account', 'Menu'],
        'Notice' => ['Master'],
        'Upload' => ['Master'],
        'Records' => ['Master', 'Authz', 'Menu', 'Upload'],
        'Ai' => ['Master', 'Authz', 'Records'],
        'Workflow' => ['Master', 'Authz', 'Menu', 'Notice', 'Records', 'Upload'],
    ];

    /**
     * 移行中の一覧(docs の 設計/コード構成/README.md 2 節「公開の口」、検査.md 6 節): 公開の口の規則({@see PublicApiRules::publicApiDependencies()})の
     * 前から、ほかのモジュールに内部(Api の下以外の業務処理・読み取り)を使われている土台のモジュール。載っている間は内部への依存を許す。
     * 減らすだけでモジュールを足さない(足すと tests/Architecture/ModulesTest.php が失敗する)。外すのは、そのモジュールの公開の口を Api に出し、
     * ほかのモジュールの呼び手を付け替えたとき。宣言のほかのモジュール(Records と後の Workflow など)は最初から規則の対象。
     *
     * @var list<string>
     */
    public const TRANSITIONAL = ['Master', 'Authz', 'Menu', 'Account'];

    /** 公開の口の名前空間の段の名前(App\Actions\<M>\Api・App\Queries\<M>\Api) */
    public const PUBLIC_API = 'Api';

    /**
     * モジュールのサブ名前空間 <M> を持つ置き場(コード構成 1 節の 10 = 入口の 4・業務処理・読み取り・永続化・列挙と、キューのジョブ・コマンド)。
     * ディレクトリは名前空間と同じ形(App\ = app/)
     */
    public const NAMESPACES = [
        'App\Http\Controllers\Web',
        'App\Http\Controllers\Api',
        'App\Http\Requests',
        'App\Http\Middleware',
        'App\Actions',
        'App\Queries',
        'App\Models',
        'App\Enums',
        'App\Jobs',
        'App\Console\Commands',
    ];

    /** 画面側のページの置き場(コード構成 5 節。直下はモジュールのディレクトリだけ) */
    public const PAGES_DIRECTORY = 'resources/js/pages';

    /** 画面側の部品の置き場(コード構成 5 節。直下のディレクトリは宣言したモジュールと ui だけ。直下のファイル = 共通の部品は置ける) */
    public const COMPONENTS_DIRECTORY = 'resources/js/components';

    /** 画面側の lib の置き場(コード構成 5 節。直下のディレクトリは宣言したモジュールだけ。直下のファイル = 共通は置ける) */
    public const LIB_DIRECTORY = 'resources/js/lib';

    /** 画面側の hooks の置き場(コード構成 5 節。直下のディレクトリは宣言したモジュールだけ。直下のファイル = 共通は置ける) */
    public const HOOKS_DIRECTORY = 'resources/js/hooks';

    /**
     * 宣言したモジュールの名前(一覧の順)。
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::DECLARATION);
    }

    /**
     * モジュール $module の置き場の名前空間の選択(phpat。モジュールの規則 {@see LayerRules::moduleDependencies()} と
     * 公開の口の規則 {@see PublicApiRules} が使う)。
     *
     * @return list<SelectorInterface>
     */
    public static function selectors(string $module): array
    {
        return array_map(fn (string $place): SelectorInterface => Selector::inNamespace($place.'\\'.$module), self::NAMESPACES);
    }
}
