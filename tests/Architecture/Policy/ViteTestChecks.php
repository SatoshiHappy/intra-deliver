<?php

namespace Tests\Architecture\Policy;

use Tests\Architecture\Engine\CheckResult;
use Tests\Architecture\Engine\Directories;
use Tests\Architecture\Engine\ExclusionLedger;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\LedgerMatch;
use Tests\Architecture\Engine\Paths;
use Tests\Architecture\Engine\SourceTokens;
use Tests\Architecture\Engine\Values;
use Tests\Architecture\Engine\ViteConfig;

/**
 * #6 抜け道の照合のうち、画面の単体テスト(vp test)の分: テストの中の skip・対象の絞り込み、走らせるコマンドの引数、設定の対象の絞り込み
 * (docs の 設計/コード構成/検査.md 6 節)。PHP の抑制の照合({@see LoopholeChecks::suppressionTokens()})とは走査の範囲と読み方が違うので分けた。
 *
 * ツールは $toolRoot から、照合する設定とソースは $targetRoot から読む(自己証明は $targetRoot だけを fixture に差し替える)。
 */
final class ViteTestChecks
{
    public function __construct(
        private readonly string $toolRoot,
        private readonly string $targetRoot,
        private readonly mixed $exclusions,
    ) {}

    /**
     * テストのファイル(vite.config.ts の include と同じ glob)の skip・only・条件付き実行・成否の反転のトークン。
     * 出現したトークンは、⑧ #6 の rule に登録されていれば可。文字列とコメントの中も数える(TS の文字列を伏せない)。
     */
    public function suppressionTokens(): CheckResult
    {
        $excluded = $this->ledger()->paths(Verification::SUPPRESSION_CHECKS);
        $files = SourceTokens::globFiles($this->targetRoot, Verification::VITE_TEST_INCLUDE, $excluded);
        $occurrences = SourceTokens::inText($this->targetRoot, $files, Verification::VITE_TEST_SUPPRESSION_TOKENS);

        return new CheckResult(
            $this->ledger()->uncovered(Verification::SUPPRESSION_CHECKS, $occurrences),
            sprintf(
                '%s の %d ファイル(⑧ %s の path %d 件を除く)で出現 %d 件(すべて⑧に登録済み)',
                implode('・', Verification::VITE_TEST_INCLUDE),
                count($files),
                implode('・', Verification::SUPPRESSION_CHECKS),
                count($excluded),
                count($occurrences),
            ),
        );
    }

    /**
     * 走らせるコマンドの引数: package.json の test:unit が Policy の値と一致し、test:unit 以外の script が vp test・vitest を呼ばず、
     * scripts/ci(シェルのファイルだけ)で test:unit が出る行は npm run test:unit で終わり、scripts/ci/front がその行を 1 つ持ち、
     * vp test・vitest を直に呼ぶ行が無いこと(引数で対象を絞る・別の設定を読ませる・別の script 経由で呼ぶ道を、オプションを列挙せずに止める)。
     */
    public function scripts(): CheckResult
    {
        $files = array_map(
            fn (string $name): string => Verification::SCRIPTS_DIRECTORY.'/'.$name,
            array_values(array_filter(
                Directories::fileNames($this->targetRoot.'/'.Verification::SCRIPTS_DIRECTORY),
                fn (string $name): bool => ! str_contains($name, '.') || str_ends_with($name, Verification::SHELL_SCRIPT_EXTENSION),
            )),
        );
        $lines = SourceTokens::shellLines($this->targetRoot, $files);

        return new CheckResult(
            [...$this->packageScriptFindings(), ...self::scriptLineFindings($lines), ...self::callerFindings($lines)],
            sprintf('%s = %s・%s の %d ファイルは %s だけで呼ぶ', Verification::VITE_TEST_SCRIPT_NAME, Verification::VITE_TEST_SCRIPT, Verification::SCRIPTS_DIRECTORY, count($files), Verification::VITE_TEST_RUN),
        );
    }

    /**
     * 対象の絞り込み: vite.config.ts の test.include が Policy の一覧と一致し(狭めても広げても NG)、test の節のキーが Policy の一覧に収まり
     * (exclude・dir・root・projects などの対象を変える設定を、列挙せずに止める)、vite.config.ts より先に読まれる設定ファイルが無いこと。
     */
    public function targets(): CheckResult
    {
        $settings = ViteConfig::testSettings($this->toolRoot, $this->targetRoot.'/'.Verification::VITE_CONFIG);
        $include = LedgerMatch::equal(Values::optionalStrings($settings, 'include', 'test'), Verification::VITE_TEST_INCLUDE);
        $keys = LedgerMatch::subset(array_map('strval', array_keys($settings)), Verification::VITE_TEST_KEYS);
        $configFiles = array_values(array_filter(
            Directories::fileNames($this->targetRoot),
            fn (string $name): bool => array_any(Verification::VITEST_CONFIG_FILES, fn (string $pattern): bool => Paths::matches($pattern, $name)),
        ));

        return new CheckResult(
            [
                ...self::findingsFor('test.include.extra', $include->extra),
                ...self::findingsFor('test.include.missing', $include->missing),
                ...self::findingsFor('test.key', $keys->extra),
                ...self::findingsFor('config.file', $configFiles),
            ],
            sprintf('test.include %s・test のキー ⊆ %s・%s なし', implode('・', Verification::VITE_TEST_INCLUDE), implode('・', Verification::VITE_TEST_KEYS), implode('・', Verification::VITEST_CONFIG_FILES)),
        );
    }

    /**
     * package.json の test:unit が Policy の値と違う指摘と、test:unit 以外の script が vp test・vitest を呼ぶ指摘(引用符の中は数えない)。
     *
     * @return list<Finding>
     */
    private function packageScriptFindings(): array
    {
        $scripts = Values::table(Values::jsonFile($this->targetRoot.'/'.Verification::PACKAGE_JSON)['scripts'] ?? null, Verification::PACKAGE_JSON.' の scripts');
        $script = $scripts[Verification::VITE_TEST_SCRIPT_NAME] ?? null;

        $findings = $script === Verification::VITE_TEST_SCRIPT
            ? []
            : [new Finding('script', Verification::VITE_TEST_SCRIPT_NAME.' = '.(is_string($script) ? $script : '(なし)'), null)];

        foreach ($scripts as $name => $command) {
            if ($name !== Verification::VITE_TEST_SCRIPT_NAME && is_string($command) && preg_match(Verification::VITE_TEST_DIRECT_RUN, SourceTokens::withoutQuoted($command)) === 1) {
                $findings[] = new Finding('script.direct', $name.' = '.$command, null);
            }
        }

        return $findings;
    }

    /**
     * test:unit の後ろに引数がある行と、vp test・vitest を直に呼ぶ行の指摘(どちらも引用符の中は数えない = 同じ code の行を見る)。
     *
     * @param  list<array{file: string, line: int, text: string, code: string}>  $lines
     * @return list<Finding>
     */
    private static function scriptLineFindings(array $lines): array
    {
        $findings = [];

        foreach ($lines as $line) {
            if (str_contains($line['code'], Verification::VITE_TEST_SCRIPT_NAME) && ! str_ends_with($line['text'], Verification::VITE_TEST_RUN)) {
                $findings[] = new Finding('test:unit.arguments', $line['file'], $line['line']);
            }

            if (preg_match(Verification::VITE_TEST_DIRECT_RUN, $line['code']) === 1) {
                $findings[] = new Finding('vp-test.direct', $line['file'], $line['line']);
            }
        }

        return $findings;
    }

    /**
     * scripts/ci/front が npm run test:unit の行を 1 つ持たない指摘(呼ばない・2 回呼ぶ = 実行記録と検査の数がずれる)。
     *
     * @param  list<array{file: string, line: int, text: string, code: string}>  $lines
     * @return list<Finding>
     */
    private static function callerFindings(array $lines): array
    {
        $calls = array_filter(
            $lines,
            fn (array $line): bool => $line['file'] === Verification::VITE_TEST_CALLER
                && str_contains($line['code'], Verification::VITE_TEST_SCRIPT_NAME)
                && str_ends_with($line['text'], Verification::VITE_TEST_RUN),
        );

        return count($calls) === 1 ? [] : [new Finding('test:unit.call', Verification::VITE_TEST_CALLER, null)];
    }

    /**
     * @param  list<string>  $items
     * @return list<Finding>
     */
    private static function findingsFor(string $ruleId, array $items): array
    {
        return array_map(fn (string $item): Finding => new Finding($ruleId, $item, null), $items);
    }

    private function ledger(): ExclusionLedger
    {
        return ExclusionLedger::fromArray($this->exclusions, Licenses::LEDGER_CHECK);
    }
}
