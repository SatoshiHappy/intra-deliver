<?php

namespace Tests\Architecture\Policy;

use Tests\Architecture\Engine\CheckResult;
use Tests\Architecture\Engine\ComposerAutoload;
use Tests\Architecture\Engine\Directories;
use Tests\Architecture\Engine\ExclusionLedger;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\GitleaksConfig;
use Tests\Architecture\Engine\JsonCommand;
use Tests\Architecture\Engine\LedgerMatch;
use Tests\Architecture\Engine\PhpStanParameters;
use Tests\Architecture\Engine\PhpUnitConfig;
use Tests\Architecture\Engine\PintConfig;
use Tests\Architecture\Engine\SourceTokens;
use Tests\Architecture\Engine\Values;

/**
 * #6 抜け道の照合: 台帳⑧に登録の無い抑制・baseline・skip・除外・条件付き実行・対象の絞り込みが 0 であることと、
 * 設定に必要な規則と suite の定義が存在すること(値は照合しない)。照合の向きは 実体 ⊆ ⑧(⑧ にだけある登録は失敗にしない)。
 *
 * ツール(vendor/bin)は $toolRoot から、照合する設定とソースは $targetRoot から読む(自己証明は $targetRoot だけを fixture に差し替える)。
 * 流用元 = chatpinet_2 の同名のクラス(無修正。コメントの参照先だけを変えた)。
 */
final class LoopholeChecks
{
    /** @var array<mixed>|null */
    private ?array $phpstanParameters = null;

    public function __construct(
        private readonly string $toolRoot,
        private readonly string $targetRoot,
        private readonly mixed $exclusions,
    ) {}

    /**
     * (a) PHP のコードとコメントの抑制トークン。走査する範囲 = phpstan の解析の対象と同じパスから、⑧ #6 の path で外したものを除いた範囲。
     * 出現したトークンは、トークンが⑧ #6 の rule に登録されていれば可。
     */
    public function suppressionTokens(): CheckResult
    {
        $excluded = $this->ledger()->paths(Verification::SUPPRESSION_CHECKS);
        $occurrences = SourceTokens::inPhpCode($this->targetRoot, Verification::SUPPRESSION_PATHS, Verification::SUPPRESSION_TOKENS, $excluded);

        return new CheckResult(
            $this->ledger()->uncovered(Verification::SUPPRESSION_CHECKS, $occurrences),
            sprintf(
                '%s の PHP(⑧ %s の path %d 件を除く)で出現 %d 件(すべて⑧に登録済み)',
                implode('・', Verification::SUPPRESSION_PATHS),
                implode('・', Verification::SUPPRESSION_CHECKS),
                count($excluded),
                count($occurrences),
            ),
        );
    }

    /**
     * (b) phpstan の excludePaths・ignoreErrors(baseline の項目を含む)。phpstan が解決した値で照合する。
     * excludePaths は⑧に登録したものだけが通る。ignoreErrors と baseline は置けない(⑧に登録しても失敗にする =
     * docs の 設計/コード構成/検査/README.md 6.2「baseline なし」)。
     */
    public function phpstan(): CheckResult
    {
        $exclusions = PhpStanParameters::exclusions($this->phpstanParameters(), $this->targetRoot);
        $excludedPaths = array_values(array_filter($exclusions, fn (array $exclusion): bool => $exclusion['key'] !== Verification::PHPSTAN_FORBIDDEN_KEY));
        $ignoredErrors = array_values(array_filter($exclusions, fn (array $exclusion): bool => $exclusion['key'] === Verification::PHPSTAN_FORBIDDEN_KEY));

        return new CheckResult(
            [
                ...$this->ledger()->unregistered(Verification::PHPSTAN_CHECKS, $excludedPaths),
                ...array_map(fn (array $exclusion): Finding => new Finding($exclusion['key'], $exclusion['value'], null), $ignoredErrors),
            ],
            sprintf('除外 %d 件(すべて⑧ %s に登録済み)・ignoreErrors と baseline なし', count($excludedPaths), implode('・', Verification::PHPSTAN_CHECKS)),
        );
    }

    /**
     * (c) Pint の exclude・notPath・notName。
     */
    public function pint(): CheckResult
    {
        $exclusions = PintConfig::exclusions(Values::jsonFile($this->targetRoot.'/'.Verification::PINT_CONFIG));

        return $this->toolExclusions(Verification::PINT_CHECKS, $exclusions);
    }

    /**
     * (g) composer.json の exclude-from-classmap(PSR-4 の検査から外す)。除外は⑧に登録したものだけが通り、
     * 逆に⑧の path のうち psr-4・psr-0 の走査に掛かるものは除外に書く(双方向 = PSR-4 の検査の走査を台帳の除外と同じにする)。
     */
    public function composer(): CheckResult
    {
        $manifest = Values::jsonFile($this->targetRoot.'/'.Verification::COMPOSER_JSON);
        $exclusions = ComposerAutoload::classmapExclusions($manifest, $this->targetRoot);
        $registered = $this->ledger()->paths(Verification::PSR4_CHECKS);
        $scanned = ComposerAutoload::overlappingPsrDirectories($registered, $manifest);
        $missing = LedgerMatch::subset($scanned, array_column($exclusions, 'value'))->extra;

        return new CheckResult(
            [
                ...$this->ledger()->unregistered(Verification::PSR4_CHECKS, $exclusions),
                ...self::findingsFor(Verification::PSR4_MISSING, $missing),
            ],
            sprintf('除外 %d 件(⑧ %s と双方向で一致)', count($exclusions), implode('・', Verification::PSR4_CHECKS)),
        );
    }

    /**
     * (d) phpunit.xml の testsuite の exclude・groups・source の exclude。場所ごとに外れる検査が違う。
     */
    public function phpunit(): CheckResult
    {
        $exclusions = PhpUnitConfig::load($this->targetRoot.'/'.Verification::PHPUNIT_CONFIG)->exclusions();
        $findings = [];

        foreach (Verification::PHPUNIT_CHECKS as $key => $checks) {
            $matching = array_values(array_filter($exclusions, fn (array $exclusion): bool => $exclusion['key'] === $key));
            array_push($findings, ...$this->ledger()->unregistered($checks, $matching));
        }

        return new CheckResult($findings, sprintf('除外 %d 件(すべて⑧に登録済み)', count($exclusions)));
    }

    /**
     * (e) 履歴の走査用の gitleaks の設定。[extend] の path が無いこと(規則は useDefault だけから取る。取り込んだ先の除外は照合されずに
     * 履歴の走査に効くため)と、allowlist(paths は正規表現の文字列のまま⑧ #11 の pattern と完全一致)と disabledRules が
     * ⑧ #11 のうち scope の無い行に登録されていること(この設定は作業木の走査用の設定にも取り込まれ、両方の走査に効く)。
     */
    public function gitleaksHistory(): CheckResult
    {
        $config = JsonCommand::toml($this->targetRoot.'/'.Verification::GITLEAKS_GIT_CONFIG);
        $extendPath = GitleaksConfig::extendPath($config);
        $exclusions = GitleaksConfig::exclusions($config);
        $findings = $this->ledger()->unregistered(Verification::GITLEAKS_CHECKS, $exclusions);

        if ($extendPath !== null) {
            array_unshift($findings, new Finding('extend.path', $extendPath, null));
        }

        return new CheckResult($findings, sprintf(
            'extend.path なし・除外 %d 件(すべて⑧ %s に登録済み)',
            count($exclusions),
            implode('・', Verification::GITLEAKS_CHECKS),
        ));
    }

    /**
     * (e) 作業木の走査用の gitleaks の設定。[extend] の path が履歴の走査用の設定であること(無いと規則が 0 本になり、何も検出せずに緑になる。
     * path は gitleaks のプロセスの作業ディレクトリ = アプリのルート基点で、履歴の走査用の設定と同じく $targetRoot の直下を指す)と、
     * この設定自身の allowlist と disabledRules が⑧ #11 のうち scope = dir の行に登録されていること(取り込んだ側の除外は gitleaksHistory が照合する)。
     */
    public function gitleaksWorkingTree(): CheckResult
    {
        $config = JsonCommand::toml($this->targetRoot.'/'.Verification::GITLEAKS_DIR_CONFIG);
        $extendPath = GitleaksConfig::extendPath($config);
        $exclusions = GitleaksConfig::exclusions($config);
        $findings = $this->ledger()->unregistered(Verification::GITLEAKS_CHECKS, $exclusions, Verification::GITLEAKS_DIR_SCOPE);

        if ($extendPath !== Verification::GITLEAKS_GIT_CONFIG) {
            array_unshift($findings, new Finding('extend.path', $extendPath ?? '(なし)', null));
        }

        return new CheckResult($findings, sprintf(
            'extend.path = %s・除外 %d 件(すべて⑧ %s の scope = %s に登録済み)',
            Verification::GITLEAKS_GIT_CONFIG,
            count($exclusions),
            implode('・', Verification::GITLEAKS_CHECKS),
            Verification::GITLEAKS_DIR_SCOPE,
        ));
    }

    /**
     * (f) 検査の実行コマンドの中の、テストの対象を絞るオプション(⑧ #1)とベースラインの使用(⑧ #11 の baseline と値が一致)。
     */
    public function scripts(): CheckResult
    {
        $files = array_map(
            fn (string $name): string => Verification::SCRIPTS_DIRECTORY.'/'.$name,
            Directories::fileNames($this->targetRoot.'/'.Verification::SCRIPTS_DIRECTORY),
        );
        $narrowing = SourceTokens::inText($this->targetRoot, $files, Verification::NARROWING_OPTIONS);
        $baselines = SourceTokens::optionValues($this->targetRoot, $files, Verification::BASELINE_OPTION);
        $findings = $this->ledger()->uncovered(Verification::NARROWING_CHECKS, $narrowing);

        foreach ($baselines as $baseline) {
            if (! $this->ledger()->registers(Verification::BASELINE_CHECKS, 'baseline', $baseline['value'])) {
                $findings[] = new Finding(Verification::BASELINE_OPTION.' '.$baseline['value'], $baseline['file'], $baseline['line']);
            }
        }

        return new CheckResult($findings, sprintf(
            '%s の %d ファイルで絞り込み %d 件・ベースラインの使用 %d 件(すべて⑧に登録済み)',
            Verification::SCRIPTS_DIRECTORY,
            count($files),
            count($narrowing),
            count($baselines),
        ));
    }

    /**
     * phpstan.neon が larastan・phpat・cognitive-complexity と自作規則を取り込み、自作規則のパラメータを渡していること。
     */
    public function phpstanSettings(): CheckResult
    {
        $parameters = $this->phpstanParameters();
        $includes = LedgerMatch::subset(Verification::PHPSTAN_INCLUDES, PhpStanParameters::configFiles($parameters, $this->toolRoot));
        $keys = LedgerMatch::subset(Verification::PHPSTAN_PARAMETERS, array_map('strval', array_keys($parameters)));

        return new CheckResult(
            [...self::findingsFor('include', $includes->extra), ...self::findingsFor('parameter', $keys->extra)],
            sprintf('include %d・パラメータ %d', count(Verification::PHPSTAN_INCLUDES), count(Verification::PHPSTAN_PARAMETERS)),
        );
    }

    /**
     * phpunit.xml に Policy の suite がすべて定義されていること。
     */
    public function phpunitSettings(): CheckResult
    {
        $suites = PhpUnitConfig::load($this->targetRoot.'/'.Verification::PHPUNIT_CONFIG)->suiteNames();

        return new CheckResult(
            self::findingsFor('testsuite', LedgerMatch::subset(Verification::suites(), $suites)->extra),
            'suite '.implode('・', Verification::suites()),
        );
    }

    /**
     * pint.json に preset があること。
     */
    public function pintSettings(): CheckResult
    {
        $config = Values::jsonFile($this->targetRoot.'/'.Verification::PINT_CONFIG);
        $keys = array_map('strval', array_keys($config));

        return new CheckResult(
            self::findingsFor('key', LedgerMatch::subset(Verification::PINT_KEYS, $keys)->extra),
            'キー '.implode('・', Verification::PINT_KEYS),
        );
    }

    /**
     * 対象の絞り込み: phpstan の解決済みの走査対象(paths)が Policy のディレクトリをすべて含むこと。
     */
    public function phpstanTargets(): CheckResult
    {
        $analysed = PhpStanParameters::analysedPaths($this->phpstanParameters(), $this->targetRoot);

        return new CheckResult(
            self::findingsFor('paths', LedgerMatch::subset(Verification::PHPSTAN_ANALYSED_PATHS, $analysed)->extra),
            'paths '.implode('・', $analysed),
        );
    }

    /**
     * 対象の絞り込み: phpunit.xml の各 suite の directory が Policy の一覧と一致すること(狭めても広げても、走査を絞る属性があっても NG)。
     */
    public function phpunitTargets(): CheckResult
    {
        $actual = PhpUnitConfig::load($this->targetRoot.'/'.Verification::PHPUNIT_CONFIG)->suiteDirectories();
        $findings = [];

        foreach (Verification::PHPUNIT_SUITE_DIRECTORIES as $suite => $expected) {
            $difference = LedgerMatch::equal($actual[$suite] ?? [], $expected);
            array_push(
                $findings,
                ...self::findingsFor('directory.extra', array_map(fn (string $path): string => "{$suite} {$path}", $difference->extra)),
                ...self::findingsFor('directory.missing', array_map(fn (string $path): string => "{$suite} {$path}", $difference->missing)),
            );
        }

        return new CheckResult($findings, 'suite ごとに tests/<suite 名>');
    }

    /**
     * 対象の絞り込み: pint.json に走査対象を絞る設定(in)と、照合できない設定の継承(extend)が無いこと(= アプリのルート全体)。
     */
    public function pintTargets(): CheckResult
    {
        $narrowing = PintConfig::narrowing(Values::jsonFile($this->targetRoot.'/'.Verification::PINT_CONFIG));

        return new CheckResult(
            array_map(fn (array $setting): Finding => new Finding($setting['key'], $setting['value'], null), $narrowing),
            '走査対象 = アプリのルート全体(in・extend なし)',
        );
    }

    /**
     * @param  list<string>  $checks
     * @param  list<array{key: string, kind: string, value: string}>  $exclusions
     */
    private function toolExclusions(array $checks, array $exclusions): CheckResult
    {
        return new CheckResult(
            $this->ledger()->unregistered($checks, $exclusions),
            sprintf('除外 %d 件(すべて⑧ %s に登録済み)', count($exclusions), implode('・', $checks)),
        );
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

    /**
     * @return array<mixed>
     */
    private function phpstanParameters(): array
    {
        return $this->phpstanParameters ??= PhpStanParameters::dump($this->toolRoot, $this->targetRoot.'/'.Verification::PHPSTAN_CONFIG);
    }
}
