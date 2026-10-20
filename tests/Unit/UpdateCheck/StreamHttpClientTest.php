<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\BudgetExceededException;
use UpdateCheck\StreamHttpClient;
use UpdateCheck\TimeBudget;
use UpdateCheck\UnconfirmedException;

/**
 * HTTP の要求は要求全体の締め切り(= min(要求の上限、予算の残り))で打ち切る。本物のソケットで、少しずつ返す相手を立てて確かめる。
 */
final class StreamHttpClientTest extends UpdateCheckTestCase
{
    /** @var resource|null */
    private $server;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server, 9);
            proc_close($this->server);
        }

        parent::tearDown();
    }

    /**
     * 見出しを $headerDelayMicroseconds 遅らせ、本文を 0.3 秒に 1 バイトずつ返す相手を立て、そのポートを返す。
     */
    private function dripServer(int $headerDelayMicroseconds = 0): int
    {
        $code = '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\\n"; '
            .'$c = stream_socket_accept($s, 20); fread($c, 4096); usleep('.$headerDelayMicroseconds.'); '
            .'fwrite($c, "HTTP/1.1 200 OK\\r\\nContent-Length: 1000\\r\\n\\r\\n"); '
            .'for ($i = 0; $i < 1000; $i++) { fwrite($c, "x"); usleep(300000); }';
        $process = proc_open(['php', '-r', $code], [1 => ['pipe', 'w']], $pipes);

        $this->assertIsResource($process);
        $this->server = $process;

        return (int) trim((string) fgets($pipes[1]));
    }

    public function test_少しずつ返す相手は予算で縮めた締め切りで打ち切り_予算切れにする(): void
    {
        $port = $this->dripServer();
        $start = microtime(true);

        try {
            (new StreamHttpClient(TimeBudget::starting(2)))->request('GET', "http://127.0.0.1:{$port}/");
            $this->fail('予算切れの例外になるはず');
        } catch (BudgetExceededException $exception) {
            $this->assertSame('時間の予算切れ', $exception->getMessage());
        }

        $this->assertLessThan(6, microtime(true) - $start);
    }

    public function test_要求の上限が締め切りなら時間切れ_予算切れではない(): void
    {
        $port = $this->dripServer();
        $start = microtime(true);

        try {
            (new StreamHttpClient(TimeBudget::starting(600), 1))->request('GET', "http://127.0.0.1:{$port}/");
            $this->fail('時間切れの例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('時間切れ', $exception->getMessage());
            $this->assertNotInstanceOf(BudgetExceededException::class, $exception);
        }

        $this->assertLessThan(6, microtime(true) - $start);
    }

    public function test_予算が切れていれば要求を始めない(): void
    {
        $this->expectException(BudgetExceededException::class);

        (new StreamHttpClient(TimeBudget::starting(-1)))->request('GET', 'http://127.0.0.1:1/');
    }

    public function test_見出しが来ない相手は締め切りの時刻の_fopen_の失敗を予算切れにする(): void
    {
        $port = $this->dripServer(30000000);
        $start = microtime(true);

        try {
            (new StreamHttpClient(TimeBudget::starting(2)))->request('GET', "http://127.0.0.1:{$port}/");
            $this->fail('予算切れの例外になるはず');
        } catch (BudgetExceededException) {
            $this->addToAssertionCount(1);
        }

        $this->assertLessThan(6, microtime(true) - $start);
    }

    public function test_要求の上限どおりの_fopen_の時間切れは時間切れ_予算切れではない(): void
    {
        $port = $this->dripServer(30000000);

        try {
            (new StreamHttpClient(TimeBudget::starting(600), 1))->request('GET', "http://127.0.0.1:{$port}/");
            $this->fail('時間切れの例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('時間切れ', $exception->getMessage());
            $this->assertNotInstanceOf(BudgetExceededException::class, $exception);
        }
    }

    public function test_締め切りの前の通信の失敗は予算で縮めていても通信できないのまま(): void
    {
        try {
            (new StreamHttpClient(TimeBudget::starting(10)))->request('GET', 'http://127.0.0.1:1/');
            $this->fail('例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('通信できない', $exception->getMessage());
            $this->assertNotInstanceOf(BudgetExceededException::class, $exception);
        }
    }

    public function test_締め切りは_fopen_の前から数える_見出しに使った時間も本文の締め切りに入る(): void
    {
        $port = $this->dripServer(1200000);
        $start = microtime(true);

        try {
            (new StreamHttpClient(TimeBudget::starting(2)))->request('GET', "http://127.0.0.1:{$port}/");
            $this->fail('予算切れの例外になるはず');
        } catch (BudgetExceededException) {
            $this->addToAssertionCount(1);
        }

        // 見出しの 1.2 秒 + 本文 2 秒 = 3.2 秒ではなく、全体で 2 秒の締め切りで打ち切る
        $this->assertLessThan(3.0, microtime(true) - $start);
    }
}
