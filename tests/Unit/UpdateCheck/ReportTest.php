<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\Report;
use UpdateCheck\Row;
use UpdateCheck\Section;
use UpdateCheck\Status;
use UpdateCheck\Text;
use UpdateCheck\Unconfirmed;

/**
 * 結果の Markdown(方針 4 節): 先頭行の件数、表、実行の URL、60,000 文字での切り詰め。
 */
final class ReportTest extends UpdateCheckTestCase
{
    private function report(string $runUrl = 'https://github.com/o/r/actions/runs/1'): Report
    {
        $rows = [
            new Row(Status::Action, ['a', '`x`|y']),
            new Row(Status::Action, ['b', 'c']),
            new Row(Status::Update, ['c', 'd']),
            new Row(Status::Unfixed, ['d', 'e']),
            new Row(Status::Info, ['e', 'f']),
        ];

        return new Report([
            new Section('#1', '一つ目', ['名前', '値'], $rows, [new Unconfirmed('npm audit', '上限(HTTP 429)'), new Unconfirmed('x', 'y')]),
            new Section('#2', '二つ目', ['名前'], [], []),
        ], $runUrl);
    }

    public function test_先頭行は件数を決まった形で書く(): void
    {
        $this->assertSame('要対応 2 件 / 更新あり 1 件 / 確認できなかった 2 件 / 土台で未修正の脆弱性 1 件', $this->report()->headline());
        $this->assertStringStartsWith($this->report()->headline()."\n", $this->report()->full());
    }

    public function test_全文に表と確認できなかったものと実行の_url_が入る(): void
    {
        $full = $this->report()->full();

        $this->assertStringContainsString('実行: https://github.com/o/r/actions/runs/1', $full);
        $this->assertStringContainsString('### 項目 1 一つ目', $full);
        $this->assertStringContainsString('| 要対応 | `a` | `x y` |', $full);
        $this->assertStringContainsString('- 確認できなかった: `npm audit` — `上限(HTTP 429)`', $full);
        $this->assertStringContainsString("### 項目 2 二つ目\n\n該当なし", $full);
    }

    public function test_実行の_url_が無ければローカルと書く(): void
    {
        $this->assertStringContainsString('実行: ローカル', $this->report('')->full());
    }

    public function test_上限以内ならコメントは全文と同じ(): void
    {
        $this->assertSame($this->report()->full(), $this->report()->comment());
    }

    public function test_上限を超えたら上限で切り_切ったことと_job_summary_を書く(): void
    {
        $comment = $this->report()->comment(300);

        $this->assertLessThanOrEqual(300, Text::length($comment));
        $this->assertStringStartsWith($this->report()->headline()."\n", $comment);
        $this->assertStringContainsString('Job Summary', $comment);
        $this->assertStringContainsString('切った', $comment);
    }

    private function dangerousReport(): Report
    {
        $rows = [];

        for ($i = 0; $i < 300; $i++) {
            $rows[] = new Row(Status::Action, ["pkg{$i} @octocat #12", "title #{$i} `@x` | y", 'z']);
        }

        return new Report([
            new Section('#1', '一つ目', ['a', 'b', 'c'], $rows, [new Unconfirmed('@who #3', 'reason @all #9')]),
            new Section('#5', '五つ目', ['a'], [], [new Unconfirmed('x', 'y')]),
        ], 'https://github.com/o/r/actions/runs/1');
    }

    private function outsideCode(string $markdown): string
    {
        return (string) preg_replace('/`[^`\n]*`/u', '', $markdown);
    }

    public function test_コード囲みの外に通知とリンクになる文字が無い(): void
    {
        $outside = $this->outsideCode($this->dangerousReport()->full());

        $this->assertDoesNotMatchRegularExpression('/#\d/', $outside);
        $this->assertStringNotContainsString('@', $outside);
    }

    public function test_切り詰めは行の境目で行い_どの行もバッククォートの数が偶数(): void
    {
        $comment = $this->dangerousReport()->comment(5000);

        $this->assertLessThanOrEqual(5000, Text::length($comment));

        foreach (explode("\n", $comment) as $line) {
            $this->assertSame(0, substr_count($line, '`') % 2, $line);
        }

        $outside = $this->outsideCode($comment);
        $this->assertDoesNotMatchRegularExpression('/#\d/', $outside);
        $this->assertStringNotContainsString('@', $outside);
        $this->assertStringContainsString('Job Summary', $comment);
    }

    public function test_切り詰めた最後の行は完成した行(): void
    {
        $comment = $this->dangerousReport()->comment(5000);
        $lines = explode("\n", $comment);
        $body = array_slice($lines, 0, -3);

        $this->assertMatchesRegularExpression('/^\| 要対応 \|.*\|$/D', (string) end($body));
    }
}
