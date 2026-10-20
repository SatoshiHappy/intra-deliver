<?php

namespace UpdateCheck;

use Throwable;

/**
 * #5 で作ったイメージの中で、PHP と PostgreSQL の版を読む(設計/更新チェック.md 1 節の #6)。使うイメージごとに判定する(PHP = app、PostgreSQL = db)。
 */
final class RuntimeVersionReader
{
    private DockerRun $docker;

    public function __construct(
        CommandRunner $commands,
        string $root,
        TimeBudget $budget,
    ) {
        $this->docker = new DockerRun($commands, $root, $budget);
    }

    public function read(ScanOutcome $outcome): RuntimeVersions
    {
        $versions = [];
        $reasons = [];

        foreach ($this->probes() as $name => [$label, $arguments, $pattern]) {
            if (! $outcome->confirmedFor($label)) {
                $reasons[$name] = '#5 が確認できなかった';

                continue;
            }

            try {
                $versions[$name] = $this->version($arguments, $pattern, $name);
            } catch (Throwable $exception) {
                $reasons[$name] = Unconfirmeds::from($name, $exception)->reason;
            }
        }

        return new RuntimeVersions($versions, $reasons);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: string}> 名前 → [使うイメージ(app・db), docker run の引数, 版を取り出す正規表現]
     */
    private function probes(): array
    {
        return [
            'PHP' => ['app', ['--entrypoint', 'php', ImageScanner::APP_IMAGE, '-r', 'echo PHP_VERSION;'], '/^(\d+\.\d+\.\d+)$/D'],
            'PostgreSQL' => ['db', ['--entrypoint', 'postgres', ImageScanner::DB_IMAGE, '--version'], '/^postgres \(PostgreSQL\) (\d+(?:\.\d+)+)/'],
        ];
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws UnconfirmedException
     */
    private function version(array $arguments, string $pattern, string $name): string
    {
        $result = $this->docker->run($arguments, 120);

        if (preg_match($pattern, trim($result->stdout), $match) !== 1) {
            throw new UnconfirmedException("{$name} の版を読めない: ".$result->tail());
        }

        return $match[1];
    }
}
