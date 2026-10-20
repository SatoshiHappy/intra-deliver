<?php

namespace Tests\Architecture\Policy;

use Closure;
use Tests\Architecture\Engine\CheckResult;

/**
 * M 照合(scripts/ci/verify)の Policy = 照合の一覧と、台帳に置かない intra-deliver 固有の値。
 *
 * 台帳(config/guardrails*.php)の値は実行時に受け取り、判定本体は Engine(tests/Architecture/Engine)に置く。
 * 照合の本体は {@see LoopholeChecks}(#6 抜け道と必要な設定)・{@see ViteTestChecks}(#6 のうち画面の単体テストの分)と {@see LedgerChecks}(#1 と台帳⑤)。
 * 流用元 = chatpinet_2 の同名のクラス(判定は docs の 設計/chatpinet_2からの流用.md 3 節)。
 */
final class Verification
{
    /** 検査の設定ファイル(アプリのルート起点) */
    public const PHPSTAN_CONFIG = 'phpstan.neon';

    public const PINT_CONFIG = 'pint.json';

    public const PHPUNIT_CONFIG = 'phpunit.xml';

    /** 秘匿情報検査の設定 = 履歴の走査用と、それを [extend] で取り込み作業木の走査だけの除外を足す作業木の走査用 */
    public const GITLEAKS_GIT_CONFIG = '.gitleaks.toml';

    public const GITLEAKS_DIR_CONFIG = '.gitleaks-dir.toml';

    /**
     * #6 (a) 抑制トークンを走査するパス(アプリのルート起点。ディレクトリは配下の *.php を再帰的に、ファイルは拡張子を問わず走査する)。
     * phpstan の解析の対象(PHPSTAN_ANALYSED_PATHS)と同じにし、台帳⑧ #6 の path で外したものを除く(解析するのに抑制を数えない場所を作らない。
     * docs の 設計/chatpinet_2からの流用.md 3 節の Verification の行)。
     */
    public const SUPPRESSION_PATHS = self::PHPSTAN_ANALYSED_PATHS;

    /** #6 (a) 抑制・skip・条件付き実行・対象の絞り込みのトークン(コードとコメントの中の出現を数える) */
    public const SUPPRESSION_TOKENS = [
        '@phpstan-ignore',
        'phpstan-ignore-next-line',
        'phpstan-ignore-line',
        'ast-grep-ignore',
        '@codeCoverageIgnore',
        'markTestSkipped',
        'markTestIncomplete',
        '#[RequiresPhp',
        '#[RequiresOperatingSystem',
        '#[RequiresPhpExtension',
        '@group',
        '#[Group(',
    ];

    /** #6 (f) 検査の実行コマンドの置き場と、テストの対象を絞るオプション・ベースラインの使用のオプション */
    public const SCRIPTS_DIRECTORY = 'scripts/ci';

    public const NARROWING_OPTIONS = ['--filter', '--exclude-group', '--group', '--testsuite'];

    public const BASELINE_OPTION = '--baseline-path';

    /**
     * 除外・抑制を登録する台帳⑧のキー(= その除外で外れる検査)。
     * 1 回の実行で複数の検査を行うツールの除外は、外れる検査のすべてに登録を要求する(ExclusionLedger)。
     */
    public const SUPPRESSION_CHECKS = ['#6'];

    public const PHPSTAN_CHECKS = ['B1', '#2', '#3'];

    /** phpstan の設定のうち、台帳⑧に登録しても置けないもの(ignoreErrors。baseline の項目もここに入る) */
    public const PHPSTAN_FORBIDDEN_KEY = 'ignoreErrors';

    public const PINT_CHECKS = ['#2'];

    /**
     * PSR-4 の検査(scripts/ci/psr4。ディレクトリと名前空間の一致 = コード構成の境界)の除外
     * (composer.json の exclude-from-classmap)を登録する台帳⑧のキーと、⑧に登録したのに除外に無いものの指摘の規則 ID。
     */
    public const PSR4_CHECKS = ['B1'];

    public const PSR4_MISSING = 'exclude-from-classmap.missing';

    /** phpunit.xml の除外の場所 → 台帳⑧のキー(testsuite と group の絞り込み = #1、カバレッジの対象の除外 = #5) */
    public const PHPUNIT_CHECKS = [
        'testsuite.exclude' => ['#1'],
        'groups.include' => ['#1'],
        'groups.exclude' => ['#1'],
        'source.exclude' => ['#5'],
    ];

    public const GITLEAKS_CHECKS = ['#11'];

    /** 作業木の走査用の設定(GITLEAKS_DIR_CONFIG)自身の除外を登録する台帳⑧ #11 の行の scope */
    public const GITLEAKS_DIR_SCOPE = 'dir';

    public const NARROWING_CHECKS = ['#1'];

    public const BASELINE_CHECKS = ['#11'];

    /** #6 設定に必要な規則と suite の定義(値は照合しない) */
    public const PHPSTAN_INCLUDES = [
        'vendor/larastan/larastan/extension.neon',
        'vendor/phpat/phpat/extension.neon',
        'vendor/tomasvotruba/cognitive-complexity/config/extension.neon',
        'tests/Architecture/Engine/PhpStan/rules.neon',
    ];

    /** 自作規則(物理行・Octane 安全 (a)(b)・コード構成の規則)のパラメータ。rules.neon が登録する規則はこれが無いと組み立てられない */
    public const PHPSTAN_PARAMETERS = ['physicalLines', 'forbiddenConstructorParameterTypes', 'forbiddenStaticState', 'codeLayout', 'singleClassFile', 'classOnlyFile', 'forbiddenExpressionTypes', 'sideEffects'];

    /**
     * #6 対象の絞り込み: phpstan が走査するパス(解決済みの paths が含むこと)と、suite ごとに走査するディレクトリ(一致)。
     * phpstan のパス = アプリのルート全体(`.`)と、拡張子が無く `.` の走査では拾われない artisan(docs の 設計/コード構成/README.md 4 節)。
     * 解析から外すもの(excludePaths)は台帳⑧に載せたものだけ({@see LoopholeChecks::phpstan()})。
     */
    public const PHPSTAN_ANALYSED_PATHS = ['.', 'artisan'];

    public const PHPUNIT_SUITE_DIRECTORIES = [
        'Architecture' => ['tests/Architecture'],
        'Unit' => ['tests/Unit'],
        'Feature' => ['tests/Feature'],
    ];

    public const PINT_KEYS = ['preset'];

    /**
     * #6 画面の単体テスト(vp test。docs の 設計/コード構成/検査.md 6 節)の照合 {@see ViteTestChecks}: 設定ファイルと、走らせるテストのファイル
     * (test.include。一致)、test の節に書けるキー、vite.config.ts より先に読まれる設定ファイル(vitest の設定と、vite-plus が先に読む vite.config.ts 以外の vite.config.*。置けない)。
     */
    public const VITE_CONFIG = 'vite.config.ts';

    public const VITE_TEST_INCLUDE = ['resources/js/**/*.test.ts'];

    public const VITE_TEST_KEYS = ['include', 'env'];

    /**
     * vite.config.ts より先に読まれる設定ファイル。vite-plus は vite.config の js・mjs を ts より先に読む(node_modules/vite-plus の VITE_CONFIG_FILES の並び)ので、
     * ts 以外の拡張子もすべて置けない。
     */
    public const VITEST_CONFIG_FILES = [
        'vitest.config.*', 'vitest.workspace.*', 'vitest.projects.*',
        'vite.config.js', 'vite.config.mjs', 'vite.config.cjs', 'vite.config.mts', 'vite.config.cts',
    ];

    /**
     * #6 テストのファイルの skip・only・todo・条件付き実行・成否の反転(後ろの区切りまで含める = `.skipped` のような名前に当たらない)。
     * 止めない残り(options の skip・分割代入した skip())は docs の 6 節。
     */
    public const VITE_TEST_SUPPRESSION_TOKENS = ['.skip(', '.skip.', '.only(', '.only.', '.todo(', '.skipIf(', '.runIf(', '.fails(', '.fails.'];

    /** #6 vp test を走らせるコマンド: package.json の script(一致)と、scripts/ci からの呼び方(後ろに引数を付けない)・直に呼ぶ形(置けない) */
    public const VITE_TEST_SCRIPT_NAME = 'test:unit';

    public const VITE_TEST_SCRIPT = 'scripts/ci/record vp-test -- vp test';

    public const VITE_TEST_RUN = 'npm run test:unit';

    /** #6 vp test を呼ぶ側: この 1 本だけが VITE_TEST_RUN の行を 1 つ持つ。scripts/ci の走査はシェルのファイル(拡張子の無いものと .sh)だけ */
    public const VITE_TEST_CALLER = 'scripts/ci/front';

    public const SHELL_SCRIPT_EXTENSION = '.sh';

    public const VITE_TEST_DIRECT_RUN = '/\bvp\s+test\b|\bvitest\b/';

    /** ⑤ 直接依存の定義 */
    public const COMPOSER_JSON = 'composer.json';

    public const COMPOSER_SECTIONS = ['require', 'require-dev'];

    public const PACKAGE_JSON = 'package.json';

    public const NPM_SECTIONS = ['dependencies', 'devDependencies', 'optionalDependencies'];

    /**
     * phpunit.xml に定義されているべき suite の名前。
     *
     * @return list<string>
     */
    public static function suites(): array
    {
        return array_keys(self::PHPUNIT_SUITE_DIRECTORIES);
    }

    /**
     * 照合の一覧(名前 → 照合)。台帳の値は照合の中で形を確かめる(形の誤りはその照合だけの失敗にする)。
     *
     * @return array<string, Closure(): CheckResult>
     */
    public static function checks(string $appRoot, mixed $allow, mixed $ledger): array
    {
        $exclusions = is_array($allow) ? ($allow['exclusions'] ?? null) : null;
        $loopholes = new LoopholeChecks($appRoot, $appRoot, $exclusions);
        $viteTests = new ViteTestChecks($appRoot, $appRoot, $exclusions);
        $ledgers = new LedgerChecks($appRoot, $ledger);

        return [
            '#1 suite の実行記録' => $ledgers->suiteRuns(...),
            '#6 抜け道 PHP の抑制トークン' => $loopholes->suppressionTokens(...),
            '#6 抜け道 TS のテストの抑制トークン' => $viteTests->suppressionTokens(...),
            '#6 抜け道 phpstan.neon' => $loopholes->phpstan(...),
            '#6 抜け道 pint.json' => $loopholes->pint(...),
            '#6 抜け道 composer.json' => $loopholes->composer(...),
            '#6 抜け道 phpunit.xml' => $loopholes->phpunit(...),
            '#6 抜け道 .gitleaks.toml' => $loopholes->gitleaksHistory(...),
            '#6 抜け道 .gitleaks-dir.toml' => $loopholes->gitleaksWorkingTree(...),
            '#6 抜け道 scripts/ci' => $loopholes->scripts(...),
            '#6 抜け道 vp test の実行コマンド' => $viteTests->scripts(...),
            '#6 必要な設定 phpstan.neon' => $loopholes->phpstanSettings(...),
            '#6 必要な設定 phpunit.xml' => $loopholes->phpunitSettings(...),
            '#6 必要な設定 pint.json' => $loopholes->pintSettings(...),
            '#6 対象の絞り込み phpstan.neon' => $loopholes->phpstanTargets(...),
            '#6 対象の絞り込み phpunit.xml' => $loopholes->phpunitTargets(...),
            '#6 対象の絞り込み pint.json' => $loopholes->pintTargets(...),
            '#6 対象の絞り込み vite.config.ts' => $viteTests->targets(...),
            '⑤ 直接依存 composer' => $ledgers->composerDependencies(...),
            '⑤ 直接依存 npm' => $ledgers->npmDependencies(...),
        ];
    }
}
