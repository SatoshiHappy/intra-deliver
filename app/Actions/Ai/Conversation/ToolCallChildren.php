<?php

namespace App\Actions\Ai\Conversation;

use App\Queries\Ai\ToolChildSettings;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

/**
 * 並べて動かす組の子の処理を、同時に動かす数の上限まで起こし、終わった所から次を起こす(docs の 設計/AI/エージェントの実行/道具の包みと止める.md
 * 9.4)。子は `php artisan` の別のプロセスで、PHP のメモリの上限は起動で渡す
 * (子は worker の `-d` を受け継がない = 公開の配備の構成の 3.6)。時間切れ・失敗の終了・読めない出力は、その子だけの失敗にして報告する
 * (ほかの子の結果を失わせない)。
 */
final readonly class ToolCallChildren implements ToolChildRunner
{
    /** 子の終わりを見る間隔(ミリ秒) */
    private const POLL_MILLISECONDS = 20;

    /**
     * @param  list<string>  $command  子を起こすコマンド(本物 = {@see self::fromSettings()})
     */
    public function __construct(private array $command, private int $timeoutSeconds) {}

    /** 本物の子(PHP のメモリの上限を付けた `php artisan ai:tool-call-child`)と設定の時間の上限 */
    public static function fromSettings(ToolChildSettings $settings): self
    {
        $memory = "memory_limit={$settings->memoryLimit()}";
        $command = [PHP_BINARY, '-d', $memory, base_path('artisan'), 'ai:tool-call-child'];

        return new self($command, $settings->timeoutSeconds());
    }

    /**
     * @param  list<ToolCallChildInput>  $inputs  呼び出しの順
     * @param  positive-int  $concurrency
     * @return list<array<string, mixed>|null> 入力の順の子の出力(失敗した子は null)
     */
    public function run(array $inputs, int $concurrency): array
    {
        $outputs = [];
        $next = 0;
        /** @var array<int, InvokedProcess> $running */
        $running = [];

        while ($next < count($inputs) || $running !== []) {
            $running = $this->filled($inputs, $next, $running, $concurrency);

            $running = $this->collected($running, $inputs, $outputs);

            if ($running !== []) {
                Sleep::for(self::POLL_MILLISECONDS)->milliseconds();
            }
        }

        return array_map(static fn (int $index): ?array => $outputs[$index] ?? null, array_keys($inputs));
    }

    /**
     * 同時に動かす数の上限まで次の子を起こす(起こせなかった子は失敗 = 出力が無い)。
     *
     * @param  list<ToolCallChildInput>  $inputs
     * @param  array<int, InvokedProcess>  $running
     * @param  positive-int  $concurrency
     * @return array<int, InvokedProcess>
     */
    private function filled(array $inputs, int &$next, array $running, int $concurrency): array
    {
        for (; $next < count($inputs) && count($running) < $concurrency; $next++) {
            $started = $this->started($inputs[$next]);

            if ($started !== null) {
                $running[$next] = $started;
            }
        }

        return $running;
    }

    /**
     * 終わった子の出力を $outputs に入れ、まだ走っている子を返す。
     *
     * @param  array<int, InvokedProcess>  $running
     * @param  list<ToolCallChildInput>  $inputs
     * @param  array<int, array<string, mixed>|null>  $outputs
     * @return array<int, InvokedProcess>
     */
    private function collected(array $running, array $inputs, array &$outputs): array
    {
        foreach ($running as $index => $process) {
            $finished = $this->finished($process, $inputs[$index]);

            if ($finished !== false) {
                $outputs[$index] = $finished;
                unset($running[$index]);
            }
        }

        return $running;
    }

    /** 子を起こす(入力が JSON にできない・起動に失敗したときは報告して null) */
    private function started(ToolCallChildInput $input): ?InvokedProcess
    {
        try {
            return Process::timeout($this->timeoutSeconds)
                ->input($input->toJson())
                ->start($this->command);
        } catch (JsonException|ProcessRuntimeException $exception) {
            report(new RuntimeException("並べて動かす組の子の処理を起こせない(呼び出し {$input->call->id})", 0, $exception));

            return null;
        }
    }

    /**
     * 子の終わり(まだ走っていれば false、失敗なら null)。
     *
     * @return array<string, mixed>|false|null
     */
    private function finished(InvokedProcess $process, ToolCallChildInput $input): array|false|null
    {
        // 終わりを先に見る(上限の直前に終わった子の出力を、時間切れで捨てないため)
        if ($process->running()) {
            try {
                $process->ensureNotTimedOut();
            } catch (ProcessTimedOutException $exception) {
                report(new RuntimeException("並べて動かす組の子の処理が時間切れ(呼び出し {$input->call->id})", 0, $exception));

                return null;
            }

            return false;
        }

        $result = $process->wait();

        return $result->successful()
            ? self::decoded($result->output(), $input)
            : self::failed($input, $result->errorOutput());
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decoded(string $output, ToolCallChildInput $input): ?array
    {
        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            report(new RuntimeException("並べて動かす組の子の処理の出力が読めない(呼び出し {$input->call->id})", 0, $exception));

            return null;
        }

        return is_array($decoded) ? $decoded : self::failed($input, '出力が JSON のオブジェクトでない');
    }

    private static function failed(ToolCallChildInput $input, string $error): null
    {
        report(new RuntimeException("並べて動かす組の子の処理が失敗した(呼び出し {$input->call->id}): {$error}"));

        return null;
    }
}
