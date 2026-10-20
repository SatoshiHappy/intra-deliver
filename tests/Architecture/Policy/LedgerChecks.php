<?php

namespace Tests\Architecture\Policy;

use Tests\Architecture\Engine\CheckResult;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\LedgerDifference;
use Tests\Architecture\Engine\LedgerMatch;
use Tests\Architecture\Engine\PhpUnitConfig;
use Tests\Architecture\Engine\SuiteRuns;
use Tests\Architecture\Engine\Values;

/**
 * #1 の suite の実行記録と台帳⑤の照合。
 *
 * 存在型(⑤)は双方向。照合するファイルは $targetRoot から読む(自己証明は $targetRoot と台帳だけを差し替える)。
 * 流用元 = chatpinet_2 の同名のクラス(③④⑥⑦・保護ファイルの照合は持たない台帳・保護の照合なので削った)。
 */
final readonly class LedgerChecks
{
    public const EXTRA = 'ledger.extra';

    public const MISSING = 'ledger.missing';

    public const JUNIT_ABSENT = 'junit.absent';

    public function __construct(
        private string $targetRoot,
        private mixed $ledger,
    ) {}

    /**
     * #1 phpunit.xml の suite(と必須の suite)がすべて junit に記録され、テスト 1 件以上・スキップ 0・エラーと失敗 0。
     * junit が無いこと(出力先の未設定を含む)は NG(対象 0 件と同一視しない)。
     */
    public function suiteRuns(): CheckResult
    {
        $config = PhpUnitConfig::load($this->targetRoot.'/'.Verification::PHPUNIT_CONFIG);
        $junit = $config->junitFile();

        if ($junit === null || ! is_file($this->targetRoot.'/'.$junit)) {
            return new CheckResult([new Finding(self::JUNIT_ABSENT, $junit ?? Verification::PHPUNIT_CONFIG.' に junit の出力先が無い', null)], '');
        }

        $suites = array_values(array_unique([...Verification::suites(), ...$config->suiteNames()]));
        $runs = SuiteRuns::fromJunit($this->targetRoot.'/'.$junit, $config->suitePaths(), $this->targetRoot);

        return new CheckResult(
            SuiteRuns::findings($suites, $runs),
            implode('・', array_map(fn (string $suite): string => $suite.' '.($runs[$suite]['tests'] ?? 0), $suites)),
        );
    }

    /**
     * ⑤ composer.json の require・require-dev のパッケージ名 == ⑤ composer。
     */
    public function composerDependencies(): CheckResult
    {
        return $this->dependencies(Verification::COMPOSER_JSON, Verification::COMPOSER_SECTIONS, 'composer');
    }

    /**
     * ⑤ package.json の dependencies・devDependencies・optionalDependencies のパッケージ名 == ⑤ npm。
     */
    public function npmDependencies(): CheckResult
    {
        return $this->dependencies(Verification::PACKAGE_JSON, Verification::NPM_SECTIONS, 'npm');
    }

    /**
     * @param  list<string>  $sections
     */
    private function dependencies(string $manifest, array $sections, string $ledgerKey): CheckResult
    {
        $definition = Values::jsonFile($this->targetRoot.'/'.$manifest);
        $names = [];

        foreach ($sections as $section) {
            array_push($names, ...array_map('strval', array_keys(Values::table($definition[$section] ?? [], "{$manifest} の {$section}"))));
        }

        $dependencies = Values::table(Values::table($this->ledger, 'guardrails_ledger')['dependencies'] ?? null, '台帳⑤ dependencies');
        $ledger = Values::strings($dependencies[$ledgerKey] ?? null, "台帳⑤ dependencies.{$ledgerKey}");

        return new CheckResult(self::differences(LedgerMatch::equal($names, $ledger)), sprintf('%s のパッケージ %d 個', $manifest, count($names)));
    }

    /**
     * @return list<Finding>
     */
    private static function differences(LedgerDifference $difference): array
    {
        return [
            ...array_map(fn (string $item): Finding => new Finding(self::EXTRA, $item, null), $difference->extra),
            ...array_map(fn (string $item): Finding => new Finding(self::MISSING, $item, null), $difference->missing),
        ];
    }
}
