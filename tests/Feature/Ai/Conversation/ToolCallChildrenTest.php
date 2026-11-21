<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Conversation\ToolCallChildInput;
use App\Actions\Ai\Conversation\ToolCallChildren;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/**
 * 並べて動かす組の子の処理の起こし方(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4): 同時に動かす数の上限まで起こし、終わった所から
 * 次を起こし、出力は入力の順で返す。時間切れ・失敗の終了・読めない出力はその子だけの失敗(null)にして報告し、ほかの子の結果を失わせない。
 * 子は本物のプロセス(標準入力の呼び出しの id で振る舞いを替える PHP の 1 行)。
 */
final class ToolCallChildrenTest extends TestCase
{
    /** 呼び出しの id の頭で振る舞う子: ok = 入力の呼び出しの id を返す・slow = 2 秒待ってから返す・fail = 終了コード 1・bad = 読めない出力 */
    private const CHILD = <<<'PHP'
        $input = json_decode(stream_get_contents(STDIN), true);
        $id = $input['call']['id'];
        if (str_starts_with($id, 'slow')) { usleep(2000000); }
        if (str_starts_with($id, 'fail')) { fwrite(STDERR, 'boom'); exit(1); }
        echo str_starts_with($id, 'bad') ? 'not json' : json_encode(['id' => $id, 'at' => microtime(true)]);
        PHP;

    private static function input(string $id): ToolCallChildInput
    {
        $call = new ModelToolCall($id, 'ai_read', []);

        return new ToolCallChildInput(1, 1, 1, $call, [], 'ai_read', new AgentToolScope);
    }

    /**
     * @param  list<string>  $ids
     * @param  positive-int  $concurrency
     * @return list<array<string, mixed>|null>
     */
    private static function children(array $ids, int $concurrency, int $timeoutSeconds = 10): array
    {
        $children = new ToolCallChildren([PHP_BINARY, '-r', self::CHILD], $timeoutSeconds);

        return $children->run(array_map(self::input(...), $ids), $concurrency);
    }

    public function test_outputs_come_back_in_the_input_order_and_failures_stay_with_their_child(): void
    {
        Exceptions::fake();

        $outputs = self::children(['slow1', 'ok2', 'fail3', 'bad4', 'ok5'], 2);

        $this->assertSame(['slow1', 'ok2', null, null, 'ok5'], array_map(
            static fn (?array $output): ?string => $output['id'] ?? null,
            $outputs,
        ));
        Exceptions::assertReportedCount(2);
    }

    /** 上限 1 は順に起こす(前の子が終わってから次)。上限 2 は 2 つの遅い子が重なる */
    public function test_children_start_up_to_the_concurrency(): void
    {
        $serial = self::children(['slow1', 'ok2'], 1);
        $this->assertGreaterThan($serial[0]['at'] ?? 0, $serial[1]['at'] ?? 0);

        $started = microtime(true);
        self::children(['slow1', 'slow2'], 2);
        $this->assertLessThan(3.9, microtime(true) - $started);
    }

    /** 入力を JSON にできない子は起こさずに失敗にし、ほかの子は起こす */
    public function test_a_child_that_cannot_start_fails_alone(): void
    {
        Exceptions::fake();
        $call = new ModelToolCall('broken1', 'ai_read', ['q' => "\xB1"]);
        $broken = new ToolCallChildInput(1, 1, 1, $call, [], 'ai_read', new AgentToolScope);
        $children = new ToolCallChildren([PHP_BINARY, '-r', self::CHILD], 10);

        $outputs = $children->run([$broken, self::input('ok2')], 2);

        $this->assertSame([null, 'ok2'], [$outputs[0], $outputs[1]['id'] ?? null]);
        Exceptions::assertReportedCount(1);
    }

    public function test_a_timed_out_child_fails_alone(): void
    {
        Exceptions::fake();

        $outputs = self::children(['slow1', 'ok2'], 2, 1);

        $this->assertSame([null, 'ok2'], [$outputs[0], $outputs[1]['id'] ?? null]);
        Exceptions::assertReportedCount(1);
    }
}
