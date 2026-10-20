<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Tests\TestCase;

/**
 * テスト DB の名前の守り(Tests\TestCase::acceptedDatabaseNamePattern)の境界。DB は使わない。
 * 受ける名前 = 接尾辞で終わる名前と、並列・専用 DB の `<DB 名>_test_<英数字のトークン>`(Laravel の ParallelTesting が作る名前)。
 */
final class AcceptedDatabaseNamePatternTest extends PhpUnitTestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function names(): array
    {
        return [
            '接尾辞で終わる' => ['x_test', true],
            '並列の数字のトークン' => ['x_test_test_1', true],
            '専用 DB の英数字のトークン' => ['x_test_test_cut14', true],
            '接尾辞の後に別の語' => ['x_test_backup', false],
            '接尾辞に文字が続く' => ['x_testing', false],
            'トークンが空' => ['x_test_test_', false],
            'トークンの後に区切り' => ['x_test_test_1_', false],
            'トークンに区切りを含む' => ['x_test_test_a_b', false],
        ];
    }

    #[DataProvider('names')]
    public function test_accepts_only_the_test_database_names(string $name, bool $accepted): void
    {
        $this->assertSame($accepted, preg_match($this->pattern('_test'), $name) === 1);
    }

    private function pattern(string $suffix): string
    {
        // 判定の口は protected なので、これを公開する派生クラスを通す(本番のコードには口を足さない。アプリは起動しない)
        $exposed = new class('pattern') extends TestCase
        {
            public function patternFor(string $suffix): string
            {
                return $this->acceptedDatabaseNamePattern($suffix);
            }
        };

        return $exposed->patternFor($suffix);
    }
}
