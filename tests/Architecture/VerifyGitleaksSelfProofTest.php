<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\Values;
use Tests\Architecture\Policy\LoopholeChecks;
use UnexpectedValueException;

/**
 * M 照合の #6 抜け道のうち gitleaks の 2 つの設定の自己証明。
 *
 * 照合 = 履歴の走査用(.gitleaks.toml)に [extend] の path が無いこと・除外 ⊆ ⑧ #11 の scope の無い行 / 作業木の走査用(.gitleaks-dir.toml)の [extend] の path が
 * 履歴の走査用の設定であること・自身の除外 ⊆ ⑧ #11 の scope = dir の行 / ⑧ の scope は dir だけ(ほかの値は台帳の不正)。
 * 違反 = tests/Architecture/fixtures/verify/violating/gitleaks/ と、scope を書き換えた台帳。正常 = passing/gitleaks/ とアプリの現行の設定。
 * 共通の手順は {@see VerifySelfProofTestCase}。
 */
final class VerifyGitleaksSelfProofTest extends VerifySelfProofTestCase
{
    public function test_detects_a_dir_only_exclusion_in_the_history_config(): void
    {
        $this->assertDetects('paths', '(?:^|/)\.env$', null, self::verdict(self::loopholes(self::fixture('violating/gitleaks/history-env'))->gitleaksHistory()));
    }

    public function test_detects_an_extend_path_in_the_history_config(): void
    {
        $this->assertDetects('extend.path', 'extra.toml', null, self::verdict(self::loopholes(self::fixture('violating/gitleaks/history-extend'))->gitleaksHistory()));
    }

    public function test_detects_a_working_tree_config_without_the_extend_path(): void
    {
        $this->assertDetects('extend.path', '(なし)', null, self::verdict(self::loopholes(self::fixture('violating/gitleaks/dir-without-extend'))->gitleaksWorkingTree()));
    }

    public function test_detects_an_unregistered_path_in_the_working_tree_config(): void
    {
        $this->assertDetects(
            'paths',
            '(?:^|/)secrets\.txt$',
            null,
            self::verdict(self::loopholes(self::fixture('violating/gitleaks/dir-unregistered-path'))->gitleaksWorkingTree()),
        );
    }

    public function test_rejects_a_ledger_scope_other_than_dir(): void
    {
        $exclusions = self::exclusions();
        $exclusions['#11'] = array_map(
            fn (mixed $row): mixed => is_array($row) && ($row['scope'] ?? null) === 'dir' ? [...$row, 'scope' => 'Dir'] : $row,
            Values::table($exclusions['#11'] ?? null, 'exclusions.#11'),
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('#11 の行の scope は dir のどれか');

        (new LoopholeChecks(self::appRoot(), self::appRoot(), $exclusions))->gitleaksWorkingTree();
    }

    public function test_passes_registered_exclusions_in_both_configs(): void
    {
        $this->assertClean(self::verdict(self::loopholes(self::fixture('passing/gitleaks'))->gitleaksHistory()));
        $this->assertClean(self::verdict(self::loopholes(self::fixture('passing/gitleaks'))->gitleaksWorkingTree()));
    }

    private static function loopholes(string $targetRoot): LoopholeChecks
    {
        return new LoopholeChecks(self::appRoot(), $targetRoot, self::exclusions());
    }

    /**
     * 台帳⑧の実物。
     *
     * @return array<mixed>
     */
    private static function exclusions(): array
    {
        return Values::table(self::config('guardrails_allow')['exclusions'] ?? null, 'exclusions');
    }
}
