<?php

namespace UpdateCheck;

/**
 * proc_open で外部コマンドを実行する(配列で渡すのでシェルを介さない)。標準出力と標準エラーを別々に集める。
 * 上限は子プロセスの終了と出力の読み切り(両方のパイプが EOF)までを見張る。読み取りは 1 回の呼び出しで読む量に上限を置き、読むたびに締め切りを確かめる。
 * 子が終わった後は短い猶予(締め切りの残りがそれより短ければ残り)だけ EOF を待つ。上限を過ぎたら終了を求め(SIGTERM)、猶予の後に強制終了(SIGKILL)し、終了待ちにも上限を置く。
 * 上限が時間の予算の残りで縮められていたときの時間切れは、理由を「時間の予算切れ」にする。上限までに出力を読み切れなければ、読めた分を返さず確認できなかった。
 * 1 つのコマンドの出力の合計(標準出力 + 標準エラー)に上限を置き(既定 64MB)、超えたら子を止めて確認できなかった(書き続ける子でメモリが尽きない)。
 */
final readonly class ProcessCommandRunner implements CommandRunner
{
    /** 1 回の読み取りの呼び出しで 1 本のパイプから読む量の上限 */
    private const CHUNK = 65536;

    public function __construct(
        private TimeBudget $budget,
        private int $graceSeconds = 5,
        private int $maxOutputBytes = 67108864,
    ) {}

    public function run(array $command, string $cwd, int $timeoutSeconds = 300, bool $cleanup = false): CommandResult
    {
        $limit = $cleanup ? $timeoutSeconds : $this->budget->limit($timeoutSeconds);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            throw new UnconfirmedException('コマンドを起動できない: '.($command[0] ?? ''));
        }

        $buffers = [1 => '', 2 => ''];

        try {
            $exitCode = $this->wait($process, [1 => $pipes[1], 2 => $pipes[2]], $buffers, microtime(true) + $limit);
        } finally {
            $this->close($process, $pipes);
        }

        if ($exitCode === null) {
            throw $limit < $timeoutSeconds ? new BudgetExceededException : new UnconfirmedException("時間切れ({$limit} 秒): ".($command[0] ?? ''));
        }

        if ($exitCode === false) {
            throw new UnconfirmedException('出力を読み切れなかった: '.($command[0] ?? ''));
        }

        return new CommandResult($exitCode, $buffers[1], $buffers[2]);
    }

    /**
     * パイプを閉じ、まだ動いている子(出力が大きすぎて例外になった回など)は止める。強制終了でも終わらない子に proc_close すると待ち続けるので、動いている間は閉じない。
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function close($process, array $pipes): void
    {
        foreach ([1, 2] as $key) {
            fclose($pipes[$key]);
        }

        if (proc_get_status($process)['running']) {
            $this->stop($process);
        }

        if (! proc_get_status($process)['running']) {
            proc_close($process);
        }
    }

    /**
     * 子プロセスの終了と、両方のパイプの EOF までを待つ。上限を過ぎたら、まだ動いていれば止めて null、止まっていて出力が読み切れていなければ false。
     * 子が終わった後は、締め切りか終了から猶予の早いほうまでしか EOF を待たない(孫がパイプを握っていても、締め切りいっぱい待たない)。
     *
     * @param  resource  $process
     * @param  array<int, resource>  $streams
     * @param  array<int, string>  $buffers
     *
     * @throws UnconfirmedException 出力が大きすぎる
     */
    private function wait($process, array $streams, array &$buffers, float $deadline): int|false|null
    {
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }

        $exitCode = null;
        $stoppedAt = null;

        while (true) {
            $this->drain($streams, $buffers);
            $exitCode ??= $this->exitCodeIfStopped($process);
            $stoppedAt ??= $exitCode === null ? null : microtime(true);

            if ($exitCode !== null && $streams === []) {
                return $exitCode;
            }

            if (microtime(true) > min($deadline, ($stoppedAt ?? $deadline) + $this->graceSeconds)) {
                return $this->giveUp($process, $exitCode);
            }

            $this->pause($streams);
        }
    }

    /**
     * 終了していれば終了コード(最初に running = false を見た 1 回だけ得られる)、動いていれば null。
     *
     * @param  resource  $process
     */
    private function exitCodeIfStopped($process): ?int
    {
        $status = proc_get_status($process);

        return $status['running'] ? null : $status['exitcode'];
    }

    /**
     * 止まっているが出力を読み切れなかった(孫がパイプを握っている等)ときは false、まだ動いていれば止めて null(時間切れ)。
     *
     * @param  resource  $process
     */
    private function giveUp($process, ?int $exitCode): ?false
    {
        if ($exitCode !== null) {
            return false;
        }

        $this->stop($process);

        return null;
    }

    /**
     * 出力が届くのを短く待つ(閉じたパイプしか残っていなければ、子の終了を短く待つ)。
     *
     * @param  array<int, resource>  $streams
     */
    private function pause(array $streams): void
    {
        $read = array_values($streams);
        $write = null;
        $except = null;

        if ($read === []) {
            usleep(100000);

            return;
        }

        stream_select($read, $write, $except, 0, 200000);
    }

    /**
     * 1 回の呼び出しで 1 本のパイプから読む量に上限を置いて読む(読んだ後に締め切りを確かめられるよう、呼び出しの外のループに戻る)。
     * 出力の合計が上限を超えたら例外。
     *
     * @param  array<int, resource>  $streams
     * @param  array<int, string>  $buffers
     *
     * @throws UnconfirmedException
     */
    private function drain(array &$streams, array &$buffers): void
    {
        foreach ($streams as $key => $stream) {
            $read = 0;

            do {
                $chunk = (string) fread($stream, self::CHUNK);
                $buffers[$key] .= $chunk;
                $read += strlen($chunk);
            } while ($chunk !== '' && $read < self::CHUNK);

            if (feof($stream)) {
                unset($streams[$key]);
            }
        }

        if (strlen($buffers[1]) + strlen($buffers[2]) > $this->maxOutputBytes) {
            throw new UnconfirmedException("出力が大きすぎる(標準出力と標準エラーの合計が {$this->maxOutputBytes} バイトを超えた)");
        }
    }

    /**
     * 終了を求め(SIGTERM)、猶予の間に終わらなければ強制終了(SIGKILL)し、終了を待つ(待ちにも上限がある)。
     *
     * @param  resource  $process
     */
    private function stop($process): void
    {
        proc_terminate($process, 15);

        if ($this->exited($process, $this->graceSeconds)) {
            return;
        }

        proc_terminate($process, 9);
        $this->exited($process, $this->graceSeconds);
    }

    /**
     * @param  resource  $process
     */
    private function exited($process, int $seconds): bool
    {
        $until = microtime(true) + $seconds;

        while (microtime(true) < $until) {
            if (! proc_get_status($process)['running']) {
                return true;
            }

            usleep(100000);
        }

        return false;
    }
}
