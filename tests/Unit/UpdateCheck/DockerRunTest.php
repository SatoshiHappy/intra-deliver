<?php

namespace Tests\Unit\UpdateCheck;

use PHPUnit\Framework\Attributes\DataProvider;
use UpdateCheck\BudgetExceededException;
use UpdateCheck\CommandResult;
use UpdateCheck\DockerRun;
use UpdateCheck\TimeBudget;
use UpdateCheck\UnconfirmedException;

/**
 * `docker run` は名前つきで流し、起こそうとしたら止めた理由・例外の型によらず、必ずコンテナを名前で止めて消す
 * (クライアントを止めてもコンテナは動き続けるため)。
 */
final class DockerRunTest extends UpdateCheckTestCase
{
    /**
     * @param  array<string, CommandResult|\Throwable>  $results
     */
    private function runner(array $results): FakeCommandRunner
    {
        return new FakeCommandRunner($results + ['docker rm --force' => new CommandResult(0, '', '')]);
    }

    public function test_名前つきの_rm_で流す(): void
    {
        $runner = $this->runner(['docker run --rm img echo hi' => new CommandResult(0, 'hi', '')]);

        (new DockerRun($runner, '/x', self::budget()))->run(['img', 'echo', 'hi'], 60);

        $this->assertSame(['docker', 'run', '--rm', '--name'], array_slice($runner->calls[0], 0, 4));
        $this->assertMatchesRegularExpression('/^updcheck-[0-9a-f]{8}-1$/D', $runner->calls[0][4]);
        $this->assertSame(['img', 'echo', 'hi'], array_slice($runner->calls[0], 5));
        $this->assertCount(1, $runner->calls);
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function failures(): array
    {
        self::loadScripts();

        return [
            '時間切れで止めた' => [new UnconfirmedException('時間切れ(60 秒): docker')],
            '予算で縮めた上限で止めた(起こした後の予算切れ)' => [new BudgetExceededException],
            '起動に失敗した' => [new UnconfirmedException('コマンドを起動できない: docker')],
        ];
    }

    #[DataProvider('failures')]
    public function test_どの理由で止めても同じ名前のコンテナを_docker_rm_force_で消してから同じ例外を投げ直す(\Throwable $failure): void
    {
        $runner = $this->runner(['docker run --rm img' => $failure]);

        try {
            (new DockerRun($runner, '/x', self::budget()))->run(['img'], 60);
            $this->fail('例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertSame($failure::class, $exception::class);
        }

        $this->assertSame(['docker', 'rm', '--force', $runner->calls[0][4]], $runner->calls[1]);
    }

    public function test_消せなかったときはその旨を理由に足す(): void
    {
        $runner = $this->runner([
            'docker run --rm img' => new UnconfirmedException('時間切れ'),
            'docker rm --force' => new CommandResult(1, '', 'Error: permission denied'),
        ]);

        $this->expectExceptionMessage('を消せなかった');

        (new DockerRun($runner, '/x', self::budget()))->run(['img'], 60);
    }

    public function test_そんなコンテナは無いは理由に足さない(): void
    {
        $runner = $this->runner([
            'docker run --rm img' => new UnconfirmedException('時間切れ(60 秒): docker'),
            'docker rm --force' => new CommandResult(1, '', 'Error response from daemon: No such container: x'),
        ]);

        try {
            (new DockerRun($runner, '/x', self::budget()))->run(['img'], 60);
            $this->fail('例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertSame('時間切れ(60 秒): docker', $exception->getMessage());
        }
    }

    public function test_消せなかった予算切れは型を保ってログに残す(): void
    {
        $this->expectErrorLog();

        $runner = $this->runner([
            'docker run --rm img' => new BudgetExceededException,
            'docker rm --force' => new CommandResult(1, '', 'Error: permission denied'),
        ]);

        try {
            (new DockerRun($runner, '/x', self::budget()))->run(['img'], 60);
            $this->fail('例外になるはず');
        } catch (BudgetExceededException) {
            $this->assertStringContainsString('を消せなかった', $this->errorLog());
        }
    }

    public function test_名前は呼ぶたびに別になる(): void
    {
        $runner = $this->runner(['docker run --rm img' => new CommandResult(0, '', '')]);
        $docker = new DockerRun($runner, '/x', self::budget());

        $docker->run(['img'], 60);
        $docker->run(['img'], 60);

        $this->assertNotSame($runner->calls[0][4], $runner->calls[1][4]);
    }

    public function test_起こす前に予算が切れていれば何も流さず_rm_も流さずに予算切れ(): void
    {
        $runner = $this->runner([]);

        try {
            (new DockerRun($runner, '/x', TimeBudget::starting(-1)))->run(['img'], 60);
            $this->fail('例外になるはず');
        } catch (BudgetExceededException) {
            $this->assertSame([], $runner->calls);
        }
    }

    public function test_想定外の例外でも_rm_を流して元の例外をそのまま投げ直す(): void
    {
        $runner = $this->runner(['docker run --rm img' => new \RuntimeException('boom')]);

        try {
            (new DockerRun($runner, '/x', self::budget()))->run(['img'], 60);
            $this->fail('例外になるはず');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame(['docker', 'rm', '--force', $runner->calls[0][4]], $runner->calls[1]);
    }

    public function test_rm_が例外を投げても元の例外を置き換えない(): void
    {
        $runner = $this->runner([
            'docker run --rm img' => new UnconfirmedException('時間切れ(60 秒): docker'),
            'docker rm --force' => new \RuntimeException('rm broke'),
        ]);

        try {
            (new DockerRun($runner, '/x', self::budget()))->run(['img'], 60);
            $this->fail('例外になるはず');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('時間切れ(60 秒): docker', $exception->getMessage());
            $this->assertStringContainsString('rm broke', $exception->getMessage());
        }
    }
}
