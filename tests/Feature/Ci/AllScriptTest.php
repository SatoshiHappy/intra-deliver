<?php

namespace Tests\Feature\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Architecture\Engine\TempDirectory;
use Tests\Support\RunsCiScripts;
use Tests\TestCase;

/**
 * 一括検査 scripts/ci/all の 4 つの規則(失敗の集約・無い段・E の飛ばし・D の飛ばし。docs の 設計/コード構成/検査.md 6 節)を、偽の段で確かめる。
 * 本物の段・DB・記録は使わない。道具は {@see RunsCiScripts}。
 */
final class AllScriptTest extends TestCase
{
    use RunsCiScripts;

    private const ORDER = ['format', 'static', 'psr4', 'secrets', 'deps', 'front', 'test', 'verify', 'front --e2e'];

    public function test_all_stages_succeed_in_order_and_end_with_zero(): void
    {
        $this->installStages();

        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(self::ORDER, $this->calls());
        $this->assertStringContainsString('S static: 成功', $result->stdout);
        $this->assertStringContainsString('E front --e2e: 成功', $result->stdout);
    }

    public function test_a_failed_stage_does_not_stop_the_rest_and_ends_with_one(): void
    {
        $this->installStages(['static' => 3]);

        $result = $this->runCi('all');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame(self::ORDER, $this->calls());
        $this->assertStringContainsString('S static: 失敗(終了コード 3)', $result->stdout);
    }

    public function test_a_missing_stage_script_is_a_failure_and_the_rest_continue(): void
    {
        $this->installStages();
        unlink($this->work.'/scripts/ci/psr4');

        $result = $this->runCi('all');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame(array_values(array_diff(self::ORDER, ['psr4'])), $this->calls());
        $this->assertStringContainsString('P psr4: 無い', $result->stdout);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function withAndWithoutFull(): array
    {
        return ['既定' => [[]], '--full' => [['--full']]];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('withAndWithoutFull')]
    public function test_e2e_is_skipped_when_the_front_stage_did_not_succeed(array $arguments): void
    {
        $this->installStages(['front' => 1]);

        $result = $this->runCi('all', $arguments);

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertNotContains('front --e2e', $this->calls());
        $this->assertStringContainsString('E front --e2e: 飛ばした(N が成功していない)', $result->stdout);
    }

    public function test_deps_is_skipped_on_the_same_day_when_nothing_it_reads_changed(): void
    {
        $this->installStages();
        file_put_contents($this->work.'/composer.json', '{}');

        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertFileExists($this->work.'/build/deps.verified');
        $this->resetCalls();

        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertNotContains('deps', $this->calls());
        $this->assertStringContainsString('D deps: 飛ばした(最後に成功した検査から', $result->stdout);
    }

    public function test_deps_runs_with_full_or_when_its_input_or_the_day_changed(): void
    {
        $this->installStages();
        file_put_contents($this->work.'/composer.json', '{}');
        $this->assertSame(0, $this->runCi('all')->exitCode);

        $this->resetCalls();
        $this->assertSame(0, $this->runCi('all', ['--full'])->exitCode);
        $this->assertContains('deps', $this->calls());

        $this->resetCalls();
        file_put_contents($this->work.'/composer.json', '{"changed":true}');
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertContains('deps', $this->calls());

        $this->resetCalls();
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertNotContains('deps', $this->calls());

        $this->resetCalls();
        $this->installDate('2026-10-20');
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertContains('deps', $this->calls());
    }

    public function test_a_failed_deps_leaves_no_record_so_the_next_run_runs_it_again(): void
    {
        $this->installStages();
        $this->assertSame(0, $this->runCi('all')->exitCode);
        $this->assertFileExists($this->work.'/build/deps.verified');

        $this->installStages(['deps' => 1]);
        $this->assertSame(1, $this->runCi('all', ['--full'])->exitCode);
        $this->assertFileDoesNotExist($this->work.'/build/deps.verified');

        $this->installStages();
        $this->resetCalls();
        $result = $this->runCi('all');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertContains('deps', $this->calls());
    }

    public function test_deps_fails_when_the_key_cannot_be_computed(): void
    {
        $this->installStages();
        TempDirectory::remove($this->work.'/tests/Architecture/Engine');

        $result = $this->runCi('all');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertNotContains('deps', $this->calls());
        $this->assertStringContainsString('D deps: 失敗(D の鍵を計算できない', $result->stdout);
        $this->assertContains('verify', $this->calls());
    }
}
