<?php

namespace Tests\Unit\UpdateCheck;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * 書くジョブの「Issue に書く」の処理(方針 4・5 節)。gh を呼ばずに、組み立てた gh の引数と終了コードを確かめる
 * (ワークフローの run をそのまま取り出し、PATH の先頭に置いた偽の gh で呼び出しを記録する)。
 */
final class PublishStepTest extends UpdateCheckTestCase
{
    private const HEADLINE = '要対応 1 件 / 更新あり 2 件 / 確認できなかった %d 件 / 土台で未修正の脆弱性 0 件';

    private const GH = <<<'BASH'
        #!/usr/bin/env bash
        printf '%s\n' "$*" >> "$STUB_LOG"
        if [ "${STUB_FAIL:-}" = "$1 $2" ]; then exit 1; fi
        case "$1 $2" in
            "label list") printf '%s\n' "${STUB_LABELS:-}" ;;
            "issue list") printf '%s\n' "${STUB_ISSUE:-}" ;;
            "issue create") echo "https://github.com/o/r/issues/7" ;;
            "issue comment")
                for ((i = 1; i <= $#; i++)); do
                    if [ "${!i}" = "--body-file" ]; then j=$((i + 1)); cp "${!j}" "$STUB_BODY"; fi
                done ;;
        esac
        BASH;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp = sys_get_temp_dir().'/updcheck-publish-'.bin2hex(random_bytes(4));
        mkdir($this->temp.'/bin', 0777, true);
        mkdir($this->temp.'/result');
        file_put_contents($this->temp.'/bin/gh', self::GH."\n");
        chmod($this->temp.'/bin/gh', 0755);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp.'/{*,bin/*,result/*}', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        rmdir($this->temp.'/bin');
        rmdir($this->temp.'/result');
        rmdir($this->temp);

        parent::tearDown();
    }

    private function script(): string
    {
        $workflow = Yaml::parseFile(self::root().'/.github/workflows/update-check.yml');

        foreach ($workflow['jobs']['report']['steps'] as $step) {
            if (($step['name'] ?? '') === 'Issue に書く') {
                return (string) $step['run'];
            }
        }

        $this->fail('Issue に書く step が無い');
    }

    /**
     * @param  array<string, string>  $env  偽の gh への指示
     * @return array{int, list<string>, string} 終了コード・gh の呼び出し・コメントの本文(無ければ空)
     */
    private function publish(array $env = []): array
    {
        $process = new Process(['bash', '-eo', 'pipefail', '-c', $this->script()], null, $env + [
            'PATH' => $this->temp.'/bin:'.getenv('PATH'),
            'RUNNER_TEMP' => $this->temp,
            'STUB_LOG' => $this->temp.'/log',
            'STUB_BODY' => $this->temp.'/body',
            'RUN_URL' => 'https://github.com/o/r/actions/runs/9',
            'CHECK_RESULT' => 'success',
            'DOWNLOAD_OUTCOME' => 'success',
        ]);
        $process->run();

        $log = is_file($this->temp.'/log') ? file($this->temp.'/log', FILE_IGNORE_NEW_LINES) : [];

        return [(int) $process->getExitCode(), $log ?: [], is_file($this->temp.'/body') ? (string) file_get_contents($this->temp.'/body') : ''];
    }

    private function writeComment(int $unconfirmed): string
    {
        $comment = sprintf(self::HEADLINE, $unconfirmed)."\n\n本文\n";
        file_put_contents($this->temp.'/result/comment.md', $comment);

        return $comment;
    }

    public function test_件数が読めて_ラベルも_issue_も無ければ両方作ってコメントを足し_成功で終わる(): void
    {
        $comment = $this->writeComment(0);

        [$exit, $log, $body] = $this->publish();

        $this->assertSame(0, $exit);
        $this->assertSame([
            'label list --search update-check --json name --jq .[].name',
            'label create update-check --description 更新チェックの報告 --color 0e8a16',
            'issue list --label update-check --state open --limit 1 --json number --jq .[0].number // empty',
            'issue create --title 更新チェック --label update-check --body-file '.$this->temp.'/issue-body.md',
            'issue comment https://github.com/o/r/issues/7 --body-file '.$this->temp.'/result/comment.md',
        ], $log);
        $this->assertSame($comment, $body);
    }

    public function test_ラベルも開いている_issue_もあれば作らずにその_issue_へコメントを足す(): void
    {
        $this->writeComment(0);

        [$exit, $log] = $this->publish(['STUB_LABELS' => "bug\nupdate-check", 'STUB_ISSUE' => '5']);

        $this->assertSame(0, $exit);
        $this->assertSame([
            'label list --search update-check --json name --jq .[].name',
            'issue list --label update-check --state open --limit 1 --json number --jq .[0].number // empty',
            'issue comment 5 --body-file '.$this->temp.'/result/comment.md',
        ], $log);
    }

    public function test_ラベルの名前が部分一致するだけなら作る(): void
    {
        $this->writeComment(0);

        [, $log] = $this->publish(['STUB_LABELS' => 'update-check-old', 'STUB_ISSUE' => '5']);

        $this->assertStringStartsWith('label create update-check', $log[1]);
    }

    public function test_確認できなかったが_1_件以上ならコメントを書いたうえで失敗で終わる(): void
    {
        $comment = $this->writeComment(2);

        [$exit, $log, $body] = $this->publish(['STUB_ISSUE' => '5', 'STUB_LABELS' => 'update-check']);

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('issue comment 5', $log[2]);
        $this->assertSame($comment, $body);
    }

    public function test_結果のファイルが無ければ調べるジョブが失敗したと実行の_url_を書いて失敗で終わる(): void
    {
        [$exit, $log, $body] = $this->publish(['CHECK_RESULT' => 'failure', 'STUB_ISSUE' => '5', 'STUB_LABELS' => 'update-check']);

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('issue comment 5 --body-file '.$this->temp.'/failed.md', $log[2]);
        $this->assertStringContainsString('調べるジョブの結果を受け取れなかった(調べるジョブ: failure、受け取り: success)', $body);
        $this->assertStringContainsString('実行: https://github.com/o/r/actions/runs/9', $body);
    }

    public function test_先頭行の件数を読めなければ失敗として扱う(): void
    {
        file_put_contents($this->temp.'/result/comment.md', "要対応 x 件\n本文\n");

        [$exit, , $body] = $this->publish(['STUB_ISSUE' => '5', 'STUB_LABELS' => 'update-check']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('調べるジョブの結果を受け取れなかった', $body);
    }

    public function test_コメントを書けなければ失敗で終わる(): void
    {
        $this->writeComment(0);

        [$exit, $log] = $this->publish(['STUB_FAIL' => 'issue comment', 'STUB_ISSUE' => '5', 'STUB_LABELS' => 'update-check']);

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('issue comment 5', (string) end($log));
    }

    public function test_ラベルの一覧を読めなければコメントを試みずに失敗で終わる(): void
    {
        $this->writeComment(0);

        [$exit, $log] = $this->publish(['STUB_FAIL' => 'label list']);

        $this->assertSame(1, $exit);
        $this->assertCount(1, $log);
    }

    public function test_受け取りの段が失敗なら古いファイルが残っていても使わず_失敗の通知を書いて失敗で終わる(): void
    {
        $this->writeComment(0);

        [$exit, $log, $body] = $this->publish(['DOWNLOAD_OUTCOME' => 'failure', 'STUB_ISSUE' => '5', 'STUB_LABELS' => 'update-check']);

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('issue comment 5 --body-file '.$this->temp.'/failed.md', $log[2]);
        $this->assertStringNotContainsString('本文', $body);
        $this->assertStringContainsString('受け取り: failure', $body);
        $this->assertStringContainsString('実行: https://github.com/o/r/actions/runs/9', $body);
    }

    public function test_受け取りの段が失敗でもcheckが成功なら_その旨を文に書く(): void
    {
        [, , $body] = $this->publish(['DOWNLOAD_OUTCOME' => 'failure', 'CHECK_RESULT' => 'success', 'STUB_ISSUE' => '5', 'STUB_LABELS' => 'update-check']);

        $this->assertStringContainsString('調べるジョブ: success、受け取り: failure', $body);
    }
}
