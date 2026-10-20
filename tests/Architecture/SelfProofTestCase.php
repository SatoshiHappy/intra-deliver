<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\Findings;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;

/**
 * 検査の自己証明の基底クラス。
 *
 * 違反 fixture に対しては「意図した規則がその箇所を検出し、ツールが非 0 で終わる」ことを、
 * 正常 fixture に対しては「指摘が 0 件で、ツールが 0 で終わる」ことを確かめる。
 * 構文エラーや別の規則の指摘は、意図した規則の証明に数えない(規則 ID・ファイル・行で照合する)。
 *
 * 使い方:
 * 1. 検査ごとにこのクラスを継承し、{@see self::findings()} でツールの出力を指摘の一覧に変換する。
 *    出力が JSON なら {@see Findings::fromJson()}、行テキストなら {@see Findings::fromLines()} を使う
 *    (どちらの形にも合わない出力は、ツールの出力形式を選び直すか、findings() の中で組み立てる)。
 * 2. fixture は tests/Architecture/fixtures/<検査>/{violating,passing}/ に置き、
 *    通常 CI と同じ規則登録・判定本体・対象選択のまま、走査ルートだけを fixture に差し替えて
 *    {@see Process::run()} でツールを走らせる。
 * 3. 違反 fixture の結果に assertDetects('規則 ID', 'violating/Foo.php', 12, $result)、
 *    正常 fixture の結果に assertClean($result) を掛ける。行を報告しないツールは行に null を渡す。
 *
 * 例:
 *     final class ExampleSelfProofTest extends SelfProofTestCase
 *     {
 *         protected function findings(ProcessResult $result): array
 *         {
 *             return Findings::fromJson($result->stdout, 'RuleID', 'File', 'StartLine');
 *         }
 *
 *         public function test_detects_violation(): void
 *         {
 *             $result = Process::run([...], $fixtureRoot.'/violating');
 *             $this->assertDetects('rule-id', 'violating/Foo.php', 12, $result);
 *         }
 *     }
 */
abstract class SelfProofTestCase extends TestCase
{
    /**
     * ツールの出力を指摘の一覧に変換する。形が想定と違うときは例外を投げる(黙って 0 件にしない)。
     *
     * @return list<Finding>
     */
    abstract protected function findings(ProcessResult $result): array;

    /**
     * 規則 $ruleId が $relativeFile の $line 行(null なら行を問わない)を検出し、ツールが非 0 で終わったことを確かめる。
     */
    final protected function assertDetects(string $ruleId, string $relativeFile, ?int $line, ProcessResult $result): void
    {
        $findings = $this->findings($result);
        $expected = $ruleId.' '.$relativeFile.':'.($line ?? '-');

        $this->assertNotSame(0, $result->exitCode, "違反 fixture でツールが 0 で終わった(期待 = {$expected})\n".$result->describe());

        foreach ($findings as $finding) {
            if ($finding->matches($ruleId, $relativeFile, $line)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(
            "意図した規則がその箇所を検出していない(期待 = {$expected})\n報告された指摘:\n"
            .self::describeAll($findings)."\n".$result->describe(),
        );
    }

    /**
     * 指摘が 0 件で、ツールが 0 で終わったことを確かめる。
     */
    final protected function assertClean(ProcessResult $result): void
    {
        $findings = $this->findings($result);

        $this->assertSame([], $findings, "正常 fixture で指摘があった:\n".self::describeAll($findings)."\n".$result->describe());
        $this->assertSame(0, $result->exitCode, "正常 fixture でツールが非 0 で終わった\n".$result->describe());
    }

    /**
     * @param  list<Finding>  $findings
     */
    private static function describeAll(array $findings): string
    {
        if ($findings === []) {
            return '(なし)';
        }

        return implode("\n", array_map(fn (Finding $finding): string => $finding->describe(), $findings));
    }
}
