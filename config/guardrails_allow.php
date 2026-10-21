<?php

/*
 * 許容集合型の台帳(① 基底ディレクトリ・② 副作用の許可表・⑧ 検査の除外)。照合は「実体 ⊆ 台帳」で、未作成・未使用の許可があってよい。
 *
 * 持つ台帳の種類 = docs の README「レビューの規約」(⑧・⑤・①② の 3 つ)。⑤ は存在型なので config/guardrails_ledger.php。
 * 台帳はユーザーの承認を求めず、機械が照合する(目的 = AI が黙って検査を外すことの検出)。
 * 流用元 = chatpinet_2 の config/guardrails_allow.php(判定は docs の 設計/chatpinet_2からの流用.md 3 節)。
 *
 * 場所の書き方(②)= 名前空間(その名前空間と配下すべて。例 = App\Actions、App\Actions\<M>)。
 *
 * ⑧ の行の書き方 = 種類(path・rule・baseline・pattern・package)を 1 つと reason。範囲を限るときだけ scope
 * (書ける値 = tests/Architecture/Engine/ExclusionLedger.php の SCOPES)。package の行だけは期限 expires(YYYY-MM-DD。その日まで有効)を必ず持つ。
 */

// 除外の理由が共通の、依存物と生成物(⑧「依存物・生成物等の不可避の除外」。パスはアプリのルート起点)
$dependencyAndGeneratedExclusions = [
    ['path' => 'vendor/**', 'reason' => '依存物(composer が入れる外部のコード。検査対象はアプリのコード)'],
    ['path' => 'node_modules/**', 'reason' => '依存物(npm が入れる外部のコード。検査対象はアプリのコード)'],
    ['path' => 'build/**', 'reason' => '生成物(検査の成果物と取得したバイナリ。git 管理外)'],
];

// 自己証明の fixture の走査除外(fixture は通常の静的検査の走査ルートに含めない。秘匿情報検査と依存監査からは除外しない)
$fixtureExclusion = ['path' => 'tests/Architecture/fixtures/**', 'reason' => '自己証明の fixture(違反を意図して含む)。通常の走査ルートに含めない'];

// phpstan の解析(アプリのルート全体)から外すもののうち、秘匿情報検査には関わらないもの(docs の 設計/コード構成/README.md 4 節の初期値)。
// phpstan の 1 回の実行で見る検査(B1・#2・#3)と、解析の対象に揃えた抑制の走査(#6)の除外として登録する
$analysisExclusions = [
    ['path' => 'bootstrap/cache/**', 'reason' => '生成物(Laravel が書くパッケージ・サービスのキャッシュ)'],
    ['path' => 'storage/**', 'reason' => '生成物(コンパイル済みのテンプレート・ログ・セッションなど。git 管理外)'],
    ['path' => 'resources/views/**', 'reason' => 'Blade のテンプレート(phpstan が解析しない。置けるのは Inertia の根のテンプレートだけで、中身は検査しない = コード構成 4 節)'],
];

// docs の参照の形(.md で終わる語)だが docs の文書でないファイル名(参照の実在の照合 R1 が参照の語から外す。値は語と完全一致)
$nonDocumentNames = [
    ['pattern' => 'summary.md', 'reason' => '更新チェックが書く結果の要約のファイル名'],
    ['pattern' => 'comment.md', 'reason' => '更新チェックが書く Issue のコメントのファイル名'],
    ['pattern' => 'failed.md', 'reason' => '更新チェックが失敗したときに書くファイル名'],
    ['pattern' => 'issue-body.md', 'reason' => '更新チェックが書く Issue の本文のファイル名'],
    ['pattern' => 'CHANGELOG.md', 'reason' => '配布物から外すファイル名(.gitattributes の export-ignore)'],
    ['pattern' => 'LICENSE.md', 'reason' => '依存のパッケージのライセンスのファイル名'],
];

// AI の道具の生成物(参照と経緯の語 R1 だけの除外。docs の 設計/コード構成/検査/README.md 6.2。正本の .ai/ は外さない)
$assistantGeneratedExclusions = [
    ['path' => '.agents/**', 'reason' => '生成物(Laravel Boost が作る skills)'],
    ['path' => '.claude/**', 'reason' => '生成物(Laravel Boost が作る skills)'],
    ['path' => 'AGENTS.md', 'reason' => '生成物(Laravel Boost が .ai/ から作る)'],
    ['path' => 'CLAUDE.md', 'reason' => '生成物(Laravel Boost が .ai/ から作る)'],
];

return [
    // ① app/ 直下に置ける基底ディレクトリ(docs の 設計/コード構成/README.md 1 節の初期値。未作成があってよい = 部分集合)。
    // 照合 = tests/Architecture/PlacementTest(app/ の直下)。足すディレクトリには層の規則(tests/Architecture/Policy/LayerRules.php)が要る
    // (照合 = tests/Architecture/CodeLayoutTest)
    // Jobs・Console = キューのジョブとコマンド(最初に使うモジュール Ai で足した = docs の 設計/AI/データと失敗と検査.md 8 節 (e))
    'base_directories' => ['Http', 'Actions', 'Queries', 'Models', 'Enums', 'Support', 'Providers', 'Jobs', 'Console'],

    // ② 副作用の許可表(docs の 設計/コード構成/README.md 4 節。初期値は 7 種とも業務処理だけ)。副作用の種類 → 使ってよい場所(名前空間。その配下を含む)。
    // 照合 = phpstan の自作規則(tests/Architecture/Engine/PhpStan/SideEffectsRule.php。実体 ⊆ 台帳)。
    // 種類と、載せられる場所の上限は tests/Architecture/Policy/SideEffects.php(照合 = tests/Architecture/CodeLayoutTest)
    // ai・queue は LLM の呼び出し口のモジュール Ai の業務処理だけ(記録と費用の上限の確認を必ず通す。ジョブの投入も Ai の 1 か所。
    // docs の 設計/業務ツール/機械検査.md の検査 (a))。http は持ち主のモジュール(Integration)を作るときに足す(laravel/ai の HTTP は vendor の中)
    'capabilities' => [
        'dbWrite' => ['App\Actions'],
        'queue' => ['App\Actions\Ai'],
        'notification' => ['App\Actions'],
        'fileStorage' => ['App\Actions'],
        'ai' => ['App\Actions\Ai'],
        'http' => [],
        'process' => ['App\Actions'],
    ],

    // ⑧ 検査の除外。検査名 → 除外の一覧。
    'exclusions' => [
        // B1 コード構成の境界(phpstan の 1 回の実行に含まれるので、phpstan の除外はここにも登録する)。
        // PSR-4 の検査(scripts/ci/psr4)の除外 = composer.json の exclude-from-classmap もここに登録し、path のうち psr-4・psr-0 の
        // ディレクトリに掛かるものは exclude-from-classmap に書く(双方向。scripts/ci/verify が照合する)
        'B1' => [
            $fixtureExclusion,
            ...$dependencyAndGeneratedExclusions,
            ...$analysisExclusions,
            ['baseline' => 'tests/Architecture/baselines/module-internals.json', 'reason' => '内部への依存の今の数(上限。上げない)'],
        ],
        // R1 参照と経緯の語(docs の 設計/コード構成/検査/README.md 6.2。流用元に無い検査名)
        'R1' => [
            $fixtureExclusion,
            ...$dependencyAndGeneratedExclusions,
            ...$analysisExclusions,
            ...$assistantGeneratedExclusions,
            ['path' => 'package-lock.json', 'reason' => '生成物(npm の依存の解決の結果)'],
            ['path' => 'composer.lock', 'reason' => '生成物(composer の依存の解決の結果)'],
            ...$nonDocumentNames,
            ['baseline' => 'tests/Architecture/baselines/history-words.json', 'reason' => '経緯の語の今の数(上限。上げない)'],
        ],
        // #2 整形・静的解析
        '#2' => [$fixtureExclusion, ...$dependencyAndGeneratedExclusions, ...$analysisExclusions],
        // #3 大きさ
        '#3' => [
            $fixtureExclusion,
            ...$dependencyAndGeneratedExclusions,
            ...$analysisExclusions,
            ['baseline' => 'tests/Architecture/baselines/line-length.json', 'reason' => '120 字を超える行の今の数(上限。上げない)'],
            ['baseline' => 'tests/Architecture/baselines/component-size.json', 'reason' => '画面側の大きい部品の今の行数(上限。上げない)'],
        ],
        // #6 抜け道
        '#6' => [$fixtureExclusion, ...$dependencyAndGeneratedExclusions, ...$analysisExclusions],
        // #11 秘匿情報(fixture は除外しない)。gitleaks の設定の allowlists.paths(走査ルート = アプリのルート基点の正規表現。
        // gitleaks は走査ルートからの相対パス(先頭の ./ なし)で照合する)をそのまま登録する
        // (照合は文字列の一致)。scope の無い行 = .gitleaks.toml(履歴の走査用。作業木の走査用の設定にも取り込まれ、両方の走査に効く)、
        // scope = 'dir' の行 = .gitleaks-dir.toml 自身の除外(作業木の走査だけに効く)
        '#11' => [
            ['pattern' => '^vendor(?:/.*)?$', 'reason' => '依存物(composer が入れる外部のコード)'],
            ['pattern' => '^node_modules(?:/.*)?$', 'reason' => '依存物(npm が入れる外部のコード)'],
            ['pattern' => '^build(?:/.*)?$', 'reason' => '生成物(検査の成果物と取得したバイナリ。git 管理外)'],
            // 作業木の走査(gitleaks dir)は git 管理外の実ファイルも読む。実在のローカル環境ファイルは秘密の置き場そのもので commit されない(.gitignore)。
            // 作業木の走査だけの除外(scope = dir)で、履歴の走査では除外しない(commit された .env は検出する)。.env.example は除外しない
            ['pattern' => '(?:^|/)\\.env$', 'scope' => 'dir', 'reason' => '実在のローカル環境ファイル(git 管理外。秘密の置き場そのもの)'],
            ['pattern' => '(?:^|/)\\.env\\.backup$', 'scope' => 'dir', 'reason' => '同上'],
            ['pattern' => '(?:^|/)\\.env\\.production$', 'scope' => 'dir', 'reason' => '同上'],
            ['baseline' => '.gitleaks-baseline.json', 'reason' => '秘匿情報検査のベースライン(免除はこの 1 ファイルだけで、そこに無い検出は免除できない)'],
        ],
        // #7 依存(ライセンスの検査 = scripts/ci/licenses.php。許可リストは tests/Architecture/Policy/Licenses.php)。
        // 許可リストに無いライセンスの依存を一時的に通すときだけ、期限つきで登録する(0 件が通常)。
        // 値 = 'composer:<パッケージ名>' か 'npm:<パッケージ名>'(版は問わない)。期限 expires は Asia/Tokyo の暦日で、その日まで有効。期限が過ぎると失敗し、依存の実体に無い行も失敗する(台帳の水増しを残さない)。
        // 例: ['package' => 'composer:vendor/name', 'expires' => '2026-12-31', 'reason' => '代替の選定中。AGPL の依存を入れない方針(ADR 0009)の一時的な例外']
        '#7' => [],
    ],
];
