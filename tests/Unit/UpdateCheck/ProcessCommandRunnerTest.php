<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\BudgetExceededException;
use UpdateCheck\ProcessCommandRunner;
use UpdateCheck\TimeBudget;
use UpdateCheck\UnconfirmedException;

/**
 * 外部コマンドの実行(本物の子プロセスで確かめる)。上限は子プロセスの終了までを見張り、時間の予算の残りを超えない。
 */
final class ProcessCommandRunnerTest extends UpdateCheckTestCase
{
    private function runner(int $budgetSeconds = 600, int $grace = 1, int $maxOutput = 67108864): ProcessCommandRunner
    {
        return new ProcessCommandRunner(TimeBudget::starting($budgetSeconds), $grace, $maxOutput);
    }

    public function test_標準出力と標準エラーと終了コードを別々に返す(): void
    {
        $result = $this->runner()->run(['sh', '-c', 'echo out; echo err >&2; exit 3'], '/tmp');

        $this->assertSame([3, "out\n", "err\n"], [$result->exitCode, $result->stdout, $result->stderr]);
    }

    public function test_出力を閉じて走り続ける子も上限で止める(): void
    {
        $start = microtime(true);

        try {
            $this->runner()->run(['sh', '-c', 'exec >&- 2>&-; exec sleep 20'], '/tmp', 1);
            $this->fail('時間切れの例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('時間切れ', $exception->getMessage());
            $this->assertNotInstanceOf(BudgetExceededException::class, $exception);
        }

        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_終了の要求を無視する子は強制終了して_上限と猶予の内に戻る(): void
    {
        $start = microtime(true);

        try {
            $this->runner()->run(['sh', '-c', 'trap "" TERM; exec sleep 30'], '/tmp', 1);
            $this->fail('時間切れの例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('時間切れ', $exception->getMessage());
        }

        $elapsed = microtime(true) - $start;
        $this->assertGreaterThan(1.5, $elapsed);
        $this->assertLessThan(6, $elapsed);
    }

    public function test_上限は予算の残りを超えない(): void
    {
        $start = microtime(true);

        try {
            $this->runner(2)->run(['sleep', '20'], '/tmp', 300);
            $this->fail('時間切れの例外になるはず');
        } catch (BudgetExceededException $exception) {
            // 予算で縮めた上限で止めたときは、理由が「時間の予算切れ」(時間切れ(N 秒)ではない)
            $this->assertSame('時間の予算切れ', $exception->getMessage());
        }

        $this->assertLessThan(6, microtime(true) - $start);
    }

    public function test_予算が切れていればコマンドを始めずに予算切れで確認できなかった(): void
    {
        $marker = sys_get_temp_dir().'/updcheck-budget-'.bin2hex(random_bytes(4));

        try {
            (new ProcessCommandRunner(TimeBudget::starting(-1)))->run(['touch', $marker], '/tmp');
            $this->fail('予算切れの例外になるはず');
        } catch (BudgetExceededException $exception) {
            $this->assertSame('時間の予算切れ', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($marker);
    }

    public function test_後始末のコマンドは予算が切れていても自分の上限で流す(): void
    {
        $result = (new ProcessCommandRunner(TimeBudget::starting(-1)))->run(['printf', 'x'], '/tmp', 30, true);

        $this->assertSame('x', $result->stdout);
    }

    public function test_大きな出力を書いてすぐ終わる子でも出力を全部受け取る(): void
    {
        foreach ([1, 2, 3] as $round) {
            $result = $this->runner()->run(['sh', '-c', 'head -c 1048576 /dev/zero | tr "\\0" "a"; head -c 200000 /dev/zero | tr "\\0" "b" >&2'], '/tmp');

            $this->assertSame([0, 1048576, 200000], [$result->exitCode, strlen($result->stdout), strlen($result->stderr)], "回 {$round}");
        }
    }

    public function test_子が終わっても孫がパイプを握っていて読み切れなければ読めた分を返さず確認できなかった(): void
    {
        try {
            $this->runner()->run(['sh', '-c', 'echo partial; sleep 4 & exit 0'], '/tmp', 1);
            $this->fail('例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('出力を読み切れなかった', $exception->getMessage());
        }
    }

    public function test_書き続ける子は出力の合計の上限で止めて確認できなかった(): void
    {
        $start = microtime(true);

        try {
            $this->runner(600, 1, 1048576)->run(['yes'], '/tmp', 30);
            $this->fail('例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('出力が大きすぎる', $exception->getMessage());
        }

        $this->assertLessThan(8, microtime(true) - $start);
    }

    public function test_出力の上限は標準出力と標準エラーの和で数える(): void
    {
        $this->expectExceptionMessage('出力が大きすぎる');

        $this->runner(600, 1, 100000)->run(['sh', '-c', 'head -c 70000 /dev/zero | tr "\\0" "a"; head -c 70000 /dev/zero | tr "\\0" "b" >&2'], '/tmp');
    }

    public function test_締め切りを見ずに読み続けない_遅く書き続ける子も上限で止める(): void
    {
        $start = microtime(true);

        try {
            $this->runner()->run(['sh', '-c', 'while true; do echo x; done'], '/tmp', 1);
            $this->fail('時間切れの例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('時間切れ', $exception->getMessage());
        }

        $this->assertLessThan(6, microtime(true) - $start);
    }

    public function test_子が終わった後に孫がパイプを握っていても_猶予の内に戻る(): void
    {
        $start = microtime(true);

        try {
            $this->runner(600, 1)->run(['sh', '-c', 'echo done; sleep 8 & exit 0'], '/tmp', 60);
            $this->fail('例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('出力を読み切れなかった', $exception->getMessage());
        }

        // 締め切り(60 秒)いっぱいではなく、終了から猶予(1 秒)の内に戻る
        $this->assertLessThan(5, microtime(true) - $start);
    }
}
