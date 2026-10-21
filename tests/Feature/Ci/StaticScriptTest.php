<?php

namespace Tests\Feature\Ci;

use Tests\Support\RunsCiScripts;
use Tests\TestCase;

/**
 * 段 scripts/ci/static が、PHPStan の結果の控えを消してから解析することを、偽の phpstan・composer・ast-grep で確かめる
 * (docs の 設計/コード構成/検査/一括検査の段.md 6.5)。本物の解析・記録は使わない。道具は {@see RunsCiScripts}。
 */
final class StaticScriptTest extends TestCase
{
    use RunsCiScripts;

    public function test_static_clears_the_result_cache_before_the_analysis(): void
    {
        $this->installStaticTools(clearExitCode: 0);

        $result = $this->runCi('static');

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame(
            ['phpstan clear-result-cache --memory-limit=1G', 'composer types:check', 'ast-grep test', 'ast-grep scan'],
            $this->calls(),
        );
    }

    public function test_static_does_not_analyse_when_the_cache_cannot_be_cleared(): void
    {
        $this->installStaticTools(clearExitCode: 1);

        $result = $this->runCi('static');

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertSame(['phpstan clear-result-cache --memory-limit=1G'], $this->calls());
    }

    /**
     * 偽の道具は「道具の名前と引数」を calls.log に 1 行書く。phpstan は決めた終了コードで、ほかは 0 で終わる。
     * 偽の記録(scripts/ci/record)は記録せずに道具を走らせる。
     */
    private function installStaticTools(int $clearExitCode): void
    {
        $this->writeTool('composer', $this->loggingTool('composer', 0));
        $this->writeWorkFile('vendor/bin/phpstan', $this->loggingTool('phpstan', $clearExitCode));
        $this->writeWorkFile('node_modules/.bin/ast-grep', $this->loggingTool('ast-grep', 0));
        $this->writeWorkFile('scripts/ci/record', "#!/bin/sh\nshift 2\nexec \"\$@\"\n");
    }

    private function loggingTool(string $name, int $exitCode): string
    {
        $calls = escapeshellarg($this->callsPath());

        return "#!/bin/sh\nprintf '%s\\n' \"{$name} \$*\" >> {$calls}\nexit {$exitCode}\n";
    }

    private function writeWorkFile(string $path, string $script): void
    {
        $full = $this->work.'/'.$path;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        file_put_contents($full, $script);
        chmod($full, 0755);
    }
}
