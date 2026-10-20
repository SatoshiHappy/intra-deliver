<?php

namespace Tests\Unit\UpdateCheck;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use UpdateCheck\RunOptions;

/**
 * run.php の引数。受ける形は --out=<値> と --cleanup だけ(知らない引数・値の無い --out・2 回目は、25 分の検査の前に止める)。
 */
final class RunOptionsTest extends UpdateCheckTestCase
{
    public function test_引数なしは既定の出力先で_cleanup_しない(): void
    {
        $options = RunOptions::parse([], '/default');

        $this->assertSame('/default', $options->out);
        $this->assertFalse($options->cleanup);
    }

    public function test_out_と_cleanup_を受ける(): void
    {
        $options = RunOptions::parse(['--out=/x', '--cleanup'], '/default');

        $this->assertSame('/x', $options->out);
        $this->assertTrue($options->cleanup);
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('invalidArguments')]
    public function test_受けない形は例外にして理由に引数を書く(array $arguments, string $mentioned): void
    {
        try {
            RunOptions::parse($arguments, '/default');
            $this->fail('例外になるはず: '.implode(' ', $arguments));
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($mentioned, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            '値の無い --out' => [['--out'], '--out'],
            '空の --out=' => [['--out='], '--out='],
            '--out の値を別の引数にした形' => [['--out', '/x'], '--out'],
            '知らないオプション' => [['--clean'], '--clean'],
            '位置引数' => [['x'], 'x'],
            '--out の 2 回目' => [['--out=a', '--out=b'], '--out=b'],
            '--cleanup の 2 回目' => [['--cleanup', '--cleanup'], '--cleanup'],
        ];
    }
}
