<?php

namespace UpdateCheck;

use InvalidArgumentException;

/**
 * run.php のコマンドライン引数。受ける形は --out=<空でない値> と --cleanup だけで、どちらも 1 回まで。
 * getopt は知らないオプションと値の無い --out を黙って無視するので使わない(25 分の検査の後に気づくより前に止める)。
 */
final readonly class RunOptions
{
    public const USAGE = '使い方: php scripts/update-check/run.php [--out=<結果を書くディレクトリ。既定 build/update-check>] [--cleanup]';

    private function __construct(
        public string $out,
        public bool $cleanup,
    ) {}

    /**
     * @param  list<string>  $arguments  $argv からスクリプト名を除いたもの
     *
     * @throws InvalidArgumentException 値の無い --out・同じオプションの 2 回目・知らない引数
     */
    public static function parse(array $arguments, string $defaultOut): self
    {
        $out = null;
        $cleanup = false;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--out=') && $argument !== '--out=' && $out === null) {
                $out = substr($argument, strlen('--out='));
            } elseif ($argument === '--cleanup' && ! $cleanup) {
                $cleanup = true;
            } else {
                throw new InvalidArgumentException(self::reason($argument, $out !== null, $cleanup));
            }
        }

        return new self($out ?? $defaultOut, $cleanup);
    }

    private static function reason(string $argument, bool $hasOut, bool $hasCleanup): string
    {
        return match (true) {
            $argument === '--out' || $argument === '--out=' => "--out に値が無い: {$argument}",
            str_starts_with($argument, '--out=') && $hasOut, $argument === '--cleanup' && $hasCleanup => "同じオプションが 2 回ある: {$argument}",
            default => "知らない引数: {$argument}",
        };
    }
}
