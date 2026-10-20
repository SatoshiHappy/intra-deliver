<?php

namespace Tests\Unit\UpdateCheck;

use RuntimeException;
use UpdateCheck\ResultFiles;

/**
 * 結果のファイルの置き方。書き切れた組だけを置き、失敗したら不完全な結果も前の回の残りも残さない。
 * 失敗の作り方は権限に頼らない(root で走ると権限では書けてしまう)。置き場に同名のディレクトリを先に作る。
 */
final class ResultFilesTest extends UpdateCheckTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/updcheck-result-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function test_全文のまま置き_一時ファイルを残さない_無いディレクトリも作る(): void
    {
        $dir = $this->dir.'/nested/out';

        ResultFiles::write($dir, ['summary.md' => "全文\n", 'comment.md' => '短い']);

        $this->assertSame("全文\n", file_get_contents($dir.'/summary.md'));
        $this->assertSame('短い', file_get_contents($dir.'/comment.md'));
        $this->assertSame(['comment.md', 'summary.md'], $this->names($dir));
    }

    public function test_一時ファイルを書けなければ投げ_前の回の残りも含め結果のファイルを残さない(): void
    {
        mkdir($this->dir.'/summary.md.tmp', 0777, true);
        file_put_contents($this->dir.'/summary.md', '前の回');
        file_put_contents($this->dir.'/comment.md', '前の回');

        try {
            ResultFiles::write($this->dir, ['summary.md' => '今回', 'comment.md' => '今回']);
            $this->fail('例外になるはず');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($this->dir.'/summary.md.tmp', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.'/summary.md');
        $this->assertFileDoesNotExist($this->dir.'/comment.md');
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_置き換えに失敗したら投げ_先に置いた_summary_md_も残さない(): void
    {
        mkdir($this->dir.'/comment.md/inner', 0777, true);

        try {
            ResultFiles::write($this->dir, ['summary.md' => '今回', 'comment.md' => '今回']);
            $this->fail('例外になるはず');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($this->dir.'/comment.md', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->dir.'/summary.md');
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_ディレクトリを作れなければ投げる(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir.'/blocker', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->dir.'/blocker/out');

        ResultFiles::write($this->dir.'/blocker/out', ['summary.md' => 'x']);
    }

    /**
     * @return list<string>
     */
    private function names(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    /**
     * @return list<string> 置き場にある *.tmp の名前の「ファイル」(ディレクトリは数えない)
     */
    private function tmpFiles(): array
    {
        return array_values(array_filter($this->names($this->dir), fn (string $name): bool => str_ends_with($name, '.tmp') && is_file($this->dir.'/'.$name)));
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $this->removeTree($path.'/'.$entry);
            }

            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
