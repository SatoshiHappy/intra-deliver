<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\CheckResult;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\Findings;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\Values;

/**
 * M 照合(scripts/ci/verify)の自己証明の基底。
 *
 * verify と同じ照合(Policy の LoopholeChecks・LedgerChecks = 同じ判定本体・対象選択)を、照合するファイルの置き場(走査ルート)だけを
 * fixture に差し替えて呼ぶ。台帳は config/guardrails*.php の実物を使う。
 * 照合は関数なので、結果を verify と同じ終了コード(NG = 1)と指摘の JSON に写し({@see self::verdict()})、
 * 基底の判定(規則 ID・対象・行と終了コード)に掛ける。
 */
abstract class VerifySelfProofTestCase extends SelfProofTestCase
{
    protected function findings(ProcessResult $result): array
    {
        return Findings::fromJson($result->stdout, 'rule', 'file', 'line');
    }

    /**
     * 照合の結果を、verify と同じ終了コードと指摘の JSON を持つ実行結果に写す。
     */
    final protected static function verdict(CheckResult $result): ProcessResult
    {
        $findings = array_map(
            fn (Finding $finding): array => ['rule' => $finding->ruleId, 'file' => $finding->file, 'line' => $finding->line],
            $result->findings,
        );

        return new ProcessResult(['verify'], $result->exitCode(), json_encode($findings, JSON_THROW_ON_ERROR), $result->line('verify'));
    }

    /**
     * 台帳・設定の実物(config/<name>.php)。
     *
     * @return array<mixed>
     */
    final protected static function config(string $name): array
    {
        return Values::table(require self::appRoot()."/config/{$name}.php", "config/{$name}.php");
    }

    /**
     * tests/Architecture/fixtures/verify/ の下の fixture のパス。
     */
    final protected static function fixture(string $path): string
    {
        return __DIR__.'/fixtures/verify/'.$path;
    }

    final protected static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
