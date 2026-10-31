<?php

namespace Tests\Feature\Ci;

use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use Tests\Architecture\Engine\TempDirectory;
use Tests\TestCase;

/**
 * T の後に時間の長いテストのクラスを出す scripts/ci/slow-tests.php が、testcase の時間をクラスごとに足して長い順に 10 出し、
 * 読めない junit・欠けた testcase では理由を出して止まること(docs の 設計/コード構成/検査/一括検査の段.md 6.5 の並列(T))。DB を使わない。
 */
final class SlowTestsScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDirectory::create(base_path('build/fixtures'), 'slow-tests');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->root);

        parent::tearDown();
    }

    public function test_classes_are_listed_by_the_sum_of_their_testcase_times_longest_first(): void
    {
        // 同じクラスの testcase が別の testsuite に分かれても(paratest の併合・データプロバイダの入れ子)、クラスごとに足す
        $junit = $this->junit(
            '<testsuite name="CLI Arguments">'
            .'<testsuite name="Tests\Feature\ATest">'.$this->testcase('Tests\Feature\ATest', 1.0).'</testsuite>'
            .'<testsuite name="Tests\Feature\BTest">'.$this->testcase('Tests\Feature\BTest', 10.0).'</testsuite>'
            .'<testsuite name="Tests\Feature\ATest::test_rows">'
            .$this->testcase('Tests\Feature\ATest', 2.0).$this->testcase('Tests\Feature\ATest', 0.5)
            .'</testsuite>'
            .'<testsuite name="Tests\Unit\CTest">'.$this->testcase('Tests\Unit\CTest', 0.2).'</testsuite>'
            .'</testsuite>',
        );

        $result = $this->slowTests($junit);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('==> 時間の長いテストのクラス(上位 10。testcase の時間のクラスごとの和)', $result->stdout);
        $this->assertSame([
            ['10.0', '1', 'Tests\Feature\BTest'],
            ['3.5', '3', 'Tests\Feature\ATest'],
            ['0.2', '1', 'Tests\Unit\CTest'],
        ], $this->rows($result));
    }

    public function test_only_the_ten_longest_classes_are_listed_and_ties_follow_the_class_name(): void
    {
        $testcases = '';

        foreach (range(1, 12) as $number) {
            // T01〜T03 は 5 秒で T08 と並ぶ。T04〜T12 は 9 秒から 1 秒ずつ減る
            $seconds = $number <= 3 ? 5.0 : 13.0 - $number;
            $testcases .= $this->testcase(sprintf('Tests\Unit\T%02dTest', $number), $seconds);
        }

        $result = $this->slowTests($this->junit('<testsuite name="CLI Arguments">'.$testcases.'</testsuite>'));

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(
            [
                'T04Test', 'T05Test', 'T06Test', 'T07Test', 'T01Test', 'T02Test', 'T03Test', 'T08Test', 'T09Test',
                'T10Test',
            ],
            array_map(fn (array $row): string => substr($row[2], strlen('Tests\Unit\\')), $this->rows($result)),
        );
    }

    public function test_a_missing_junit_fails_with_the_reason(): void
    {
        $result = $this->slowTests($this->root.'/none.xml');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('XML ファイルが無い', $result->stderr);
        $this->assertSame('', $result->stdout);
    }

    public function test_a_testcase_without_a_class_fails_with_the_reason(): void
    {
        $junit = $this->junit('<testsuite name="CLI Arguments"><testcase name="test_orphan" time="1.0"/></testsuite>');

        $result = $this->slowTests($junit);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('class の無い testcase がある: test_orphan', $result->stderr);
        $this->assertSame('', $result->stdout);
    }

    public function test_a_testcase_without_a_time_fails_with_the_reason(): void
    {
        // 0 秒に数えると、欠けを「遅くない」と読ませる
        $junit = $this->junit('<testsuite><testcase name="test_untimed" class="ATest"/></testsuite>');

        $result = $this->slowTests($junit);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $reason = 'time が無い、または数でない testcase がある';
        $this->assertStringContainsString($reason.': ATest::test_untimed', $result->stderr);
        $this->assertSame('', $result->stdout);
    }

    public function test_a_testcase_with_a_non_numeric_time_fails_with_the_reason(): void
    {
        $junit = $this->junit('<testsuite><testcase name="test_slow" class="ATest" time="abc"/></testsuite>');

        $result = $this->slowTests($junit);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $reason = 'time が無い、または数でない testcase がある';
        $this->assertStringContainsString($reason.': ATest::test_slow', $result->stderr);
        $this->assertSame('', $result->stdout);
    }

    public function test_a_junit_without_any_testcase_fails_with_the_reason(): void
    {
        $junit = $this->junit('<testsuite name="CLI Arguments"/>');

        $result = $this->slowTests($junit);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertStringContainsString('testcase が 0 件の junit: '.$junit, $result->stderr);
        $this->assertSame('', $result->stdout);
    }

    public function test_the_total_of_the_testcase_times_is_shown_after_the_heading(): void
    {
        // 上位 10 に入らないクラスも総和に入る(止まりが testcase の外にあるかを、経過の秒と比べるため)
        $testcases = '';

        foreach (range(1, 12) as $number) {
            $testcases .= $this->testcase(sprintf('Tests\Unit\T%02dTest', $number), 1.25);
        }

        $result = $this->slowTests($this->junit('<testsuite name="CLI Arguments">'.$testcases.'</testsuite>'));

        $this->assertSame(0, $result->exitCode, $result->describe());
        $lines = explode("\n", $result->stdout);
        $this->assertStringStartsWith('==> 時間の長いテストのクラス', $lines[0]);
        $this->assertSame('testcase の時間の総和 15.0 秒(12 件)', $lines[1]);
        $this->assertCount(10, $this->rows($result));
    }

    public function test_it_takes_exactly_one_argument(): void
    {
        $result = Process::run(['php', base_path('scripts/ci/slow-tests.php')], base_path());

        $this->assertSame(2, $result->exitCode, $result->describe());
        $this->assertStringContainsString('使い方: php scripts/ci/slow-tests.php <junit のパス>', $result->stderr);
    }

    private function slowTests(string $junit): ProcessResult
    {
        return Process::run(['php', base_path('scripts/ci/slow-tests.php'), $junit], base_path());
    }

    /**
     * 中身を testsuites で包んだ junit を一時の場所に書き、そのパスを返す。
     */
    private function junit(string $body): string
    {
        $path = $this->root.'/junit.xml';
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><testsuites>'.$body.'</testsuites>');

        return $path;
    }

    private function testcase(string $class, float $seconds): string
    {
        return sprintf('<testcase name="test_case" class="%s" time="%F"/>', $class, $seconds);
    }

    /**
     * 出力の 1 クラス 1 行を [秒, 件数, クラス] にする。
     *
     * @return list<array{string, string, string}>
     */
    private function rows(ProcessResult $result): array
    {
        preg_match_all('/^ *([0-9.]+) 秒 +([0-9]+) 件  (\S+)$/m', $result->stdout, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [$match[1], $match[2], $match[3]], $matches);
    }
}
