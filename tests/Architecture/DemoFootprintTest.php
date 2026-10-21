<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Paths;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Policy\DemoFootprint;
use Tests\Architecture\Policy\Modules;

/**
 * main がデモユーザー切替を参照しないことの照合と自己証明(docs の 設計/デモユーザー切替/デモの取り付け.md 6.4 の参照の検査、
 * 設計/コード構成/README.md 3 節・検査/README.md 6.2)。持ち物と取り付けの行の一覧は {@see DemoFootprint}。
 *
 * 1. アプリのルートの作業木のファイルのうち git が無視しないもの(追跡しているものと、まだ追加していないもの)の全部を、拡張子で絞らずに読み、
 *    持ち物の外のファイルの行に語 demo が現れたら、その行が取り付けの行でない限り指摘にする。テキストとして読めないファイル(NUL を含むか、
 *    UTF-8 でないもの)は照合しない。語 = 英字と数字の続きを、英字と数字でない文字と、小文字から大文字への変わり目で区切ったもの。
 *    大文字小文字を区別せずに比べる(Demo・DEMO_ENABLED・demo.switch-user・/demo/ が当たり、codeModels・demos は当たらない)。
 * 2. モジュールの宣言で、Demo を依存先に書いたモジュールが無い。
 * 3. 自己証明: 違反 fixture(tests/Architecture/fixtures/DemoFootprint/violating。拡張子の無いシェルのファイルを含む)の語を 1 が見つけ、
 *    正常 fixture(同 passing)では、fixture 用の持ち物・取り付けの行・ほかの語・テキストでないファイルを指摘しない。
 *
 * 字句の照合なので、名前を文字列の連結で組み立てる参照は止まらない(レビューで見る)。照合の処理は持ち物のこのクラスに置く
 * (取り外すときに検査ごと消すため。Engine に置くと、取り外した後に使われない部品が残る)。
 */
final class DemoFootprintTest extends TestCase
{
    /** fixture 用の持ち物(fixture のルート起点) */
    private const FIXTURE_OWNED = ['owned/**'];

    /** fixture 用の照合しないもの */
    private const FIXTURE_UNCHECKED = ['baselines/**'];

    /** fixture 用の取り付けの行 */
    private const FIXTURE_ATTACHMENTS = ['bootstrap/providers.php' => ['DemoServiceProvider::class,']];

    public function test_no_file_outside_the_footprint_names_the_word_except_the_attachment_lines(): void
    {
        $owned = [...DemoFootprint::OWNED, ...DemoFootprint::UNCHECKED];

        $this->assertSame([], $this->occurrences(dirname(__DIR__, 2), $owned, DemoFootprint::ATTACHMENTS));
    }

    public function test_no_module_depends_on_the_removable_module(): void
    {
        $this->assertSame([], self::modulesDependingOn(Modules::DECLARATION, DemoFootprint::MODULE));
    }

    public function test_detects_the_word_outside_the_footprint_in_any_kind_of_file(): void
    {
        $this->assertEqualsCanonicalizing([
            'README.md:3',
            'app/Shown.php:3',
            'bootstrap/providers.php:4',
            'config/routes.json:2',
            'scripts/run:3',
        ], $this->occurrences(self::fixture('violating'), self::FIXTURE_OWNED, self::FIXTURE_ATTACHMENTS));
    }

    public function test_passes_the_footprint_the_attachment_lines_other_words_and_binary_files(): void
    {
        $owned = [...self::FIXTURE_OWNED, ...self::FIXTURE_UNCHECKED];

        $this->assertSame([], $this->occurrences(self::fixture('passing'), $owned, self::FIXTURE_ATTACHMENTS));
    }

    public function test_detects_a_module_that_depends_on_the_removable_module(): void
    {
        $declaration = ['Alpha' => [], 'Demo' => ['Alpha'], 'Beta' => ['Alpha', 'Demo'], 'Gamma' => ['Alpha']];

        $this->assertSame(['Beta'], self::modulesDependingOn($declaration, 'Demo'));
    }

    /**
     * $root の作業木のファイル(git が無視しないもの)のうち、持ち物の外のものの、語を含む取り付けの行でない行(`パス:行番号`)。
     *
     * @param  list<string>  $owned  持ち物と照合しないもののパスのパターン($root 起点)
     * @param  array<string, list<string>>  $attachments  取り付けの行($root 起点のパス → 行)
     * @return list<string>
     */
    private function occurrences(string $root, array $owned, array $attachments): array
    {
        $occurrences = [];

        foreach ($this->filesNotIgnoredByGit($root) as $path) {
            if (array_filter($owned, fn (string $pattern) => Paths::matches($pattern, $path)) !== []) {
                continue;
            }

            foreach (self::linesNamingTheWord((string) file_get_contents($root.'/'.$path)) as $number => $line) {
                if (! in_array($line, $attachments[$path] ?? [], true)) {
                    $occurrences[] = "{$path}:{$number}";
                }
            }
        }

        return $occurrences;
    }

    /**
     * $root の下の、git が追跡しているファイルと、まだ追加していない無視されないファイル($root 起点のパス。消したファイルは除く)。
     *
     * @return list<string>
     */
    private function filesNotIgnoredByGit(string $root): array
    {
        $result = Process::run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], $root);
        $this->assertSame(0, $result->exitCode, "git でファイルの一覧を得られない\n".$result->describe());

        return array_values(array_filter(
            array_unique(explode("\0", $result->stdout)),
            fn (string $path) => $path !== '' && is_file($root.'/'.$path),
        ));
    }

    /**
     * テキストのうち語を含む行(行番号 → 前後の空白を除いた行)。テキストとして読めない中身は空。
     *
     * @return array<int, string>
     */
    private static function linesNamingTheWord(string $content): array
    {
        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            return [];
        }

        $lines = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $index => $line) {
            if (self::namesTheWord($line)) {
                $lines[$index + 1] = trim($line);
            }
        }

        return $lines;
    }

    /**
     * 行を語に区切り(英字と数字でない文字と、小文字から大文字への変わり目)、大文字小文字を区別せずに語と同じものがあるか。
     */
    private static function namesTheWord(string $line): bool
    {
        $tokens = preg_split('/[^A-Za-z0-9]+|(?<=[a-z])(?=[A-Z])/', $line) ?: [];

        return array_filter($tokens, fn (string $token) => strcasecmp($token, DemoFootprint::WORD) === 0) !== [];
    }

    /**
     * $module を依存先に書いたモジュール(宣言の順)。
     *
     * @param  array<string, list<string>>  $declaration
     * @return list<string>
     */
    private static function modulesDependingOn(array $declaration, string $module): array
    {
        return array_keys(array_filter($declaration, fn (array $dependencies) => in_array($module, $dependencies, true)));
    }

    private static function fixture(string $kind): string
    {
        return __DIR__.'/fixtures/DemoFootprint/'.$kind;
    }
}
