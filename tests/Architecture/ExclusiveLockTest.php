<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Architecture\Engine\ExclusiveLock;
use Tests\Architecture\Engine\Process;

final class ExclusiveLockTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockFile = sys_get_temp_dir().'/exclusive-lock-test-'.bin2hex(random_bytes(8)).'.lock';
    }

    protected function tearDown(): void
    {
        if (is_file($this->lockFile)) {
            unlink($this->lockFile);
        }

        parent::tearDown();
    }

    public function test_run_returns_the_value_of_the_callback(): void
    {
        $this->assertSame('result', ExclusiveLock::run($this->lockFile, static fn (): string => 'result'));
    }

    public function test_run_holds_the_lock_against_other_processes_while_the_callback_runs(): void
    {
        $this->assertSame('busy', ExclusiveLock::run($this->lockFile, fn (): string => $this->probeFromAnotherProcess()));
    }

    public function test_run_releases_the_lock_after_the_callback(): void
    {
        ExclusiveLock::run($this->lockFile, static fn (): null => null);

        $this->assertSame('free', $this->probeFromAnotherProcess());
    }

    public function test_run_releases_the_lock_when_the_callback_throws(): void
    {
        $caught = null;

        try {
            ExclusiveLock::run($this->lockFile, static fn (): never => throw new RuntimeException('callback failed'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }

        $this->assertSame('callback failed', $caught);
        $this->assertSame('free', $this->probeFromAnotherProcess());
    }

    /**
     * 別のプロセスからロックを待たずに取りにいき、取れたら free・取れなければ busy を返す。
     */
    private function probeFromAnotherProcess(): string
    {
        $result = Process::run(
            [PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "free" : "busy";', '--', $this->lockFile],
            __DIR__,
        );

        $this->assertSame(0, $result->exitCode, $result->describe());

        return $result->stdout;
    }
}
