<?php

namespace Tests\Unit\UpdateCheck;

use Throwable;
use UpdateCheck\CommandResult;
use UpdateCheck\CommandRunner;

/**
 * 決めた出力を返す外部コマンドの代わり(実際のコマンドを呼ばない)。実行したコマンドを覚える。
 * `docker run` の `--name <名前>` と `docker rm --force <名前>` の名前は一意なので、引く鍵からは除く(calls には名前つきで残る)。結果が例外なら投げる。
 */
final class FakeCommandRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<string> */
    public array $cwds = [];

    /**
     * @param  array<string, CommandResult|Throwable>  $results  コマンド(空白でつないだ文字列)→ 結果
     */
    public function __construct(private readonly array $results) {}

    public function run(array $command, string $cwd, int $timeoutSeconds = 300, bool $cleanup = false): CommandResult
    {
        $this->calls[] = $command;
        $this->cwds[] = $cwd;

        $result = $this->results[implode(' ', $this->withoutName($command))] ?? new CommandResult(127, '', 'unknown command: '.implode(' ', $command));

        return $result instanceof Throwable ? throw $result : $result;
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function withoutName(array $command): array
    {
        // docker rm --force <名前> の名前も一意なので鍵から除く
        if (array_slice($command, 0, 3) === ['docker', 'rm', '--force']) {
            return array_slice($command, 0, 3);
        }

        $index = array_search('--name', $command, true);

        if ($index === false) {
            return $command;
        }

        array_splice($command, (int) $index, 2);

        return $command;
    }
}
