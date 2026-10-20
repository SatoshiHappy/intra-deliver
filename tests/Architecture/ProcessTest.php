<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Process;

final class ProcessTest extends TestCase
{
    public function test_run_returns_exit_code_stdout_and_stderr(): void
    {
        $result = Process::run([PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);'], __DIR__);

        $this->assertSame(3, $result->exitCode);
        $this->assertSame('out', $result->stdout);
        $this->assertSame('err', $result->stderr);
    }

    public function test_run_uses_the_given_working_directory(): void
    {
        $result = Process::run([PHP_BINARY, '-r', 'echo getcwd();'], sys_get_temp_dir());

        $this->assertSame(0, $result->exitCode);
        $this->assertSame(realpath(sys_get_temp_dir()), realpath($result->stdout));
    }

    public function test_run_passes_additional_environment_variables(): void
    {
        $result = Process::run([PHP_BINARY, '-r', 'echo getenv("GUARDRAILS_PROCESS_PROBE");'], __DIR__, ['GUARDRAILS_PROCESS_PROBE' => 'probe']);

        $this->assertSame('probe', $result->stdout);
    }
}
