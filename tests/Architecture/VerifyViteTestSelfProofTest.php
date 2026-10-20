<?php

namespace Tests\Architecture;

use Tests\Architecture\Engine\TempDirectory;
use Tests\Architecture\Policy\Verification;
use Tests\Architecture\Policy\ViteTestChecks;

/**
 * M 照合の #6 のうち、画面の単体テスト(vp test)の分の自己証明: テストの中の skip などのトークン・走らせるコマンドの引数・設定の対象の絞り込み。
 *
 * 違反と正常 = build/fixtures に一時生成するテストのファイル・package.json・scripts/ci のファイル・vite.config.ts・vite.config.js・vitest.config.ts。
 * 共通の手順は {@see VerifySelfProofTestCase}。
 */
final class VerifyViteTestSelfProofTest extends VerifySelfProofTestCase
{
    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workRoot = TempDirectory::create(self::appRoot().'/build/fixtures', 'verify-vite-test');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->workRoot);

        parent::tearDown();
    }

    public function test_detects_a_skipped_test_directly_under_the_include_root(): void
    {
        $this->write('resources/js/sample.test.ts', "import { it } from 'vite-plus/test';\n\nit.skip('a', () => {});\n");

        $this->assertDetects('.skip(', 'resources/js/sample.test.ts', 3, self::verdict($this->checks()->suppressionTokens()));
    }

    public function test_detects_a_skip_through_the_test_context(): void
    {
        $this->write('resources/js/lib/sample.test.ts', "import { it } from 'vite-plus/test';\n\nit('a', (ctx) => {\n    ctx.skip();\n});\n");

        $this->assertDetects('.skip(', 'resources/js/lib/sample.test.ts', 4, self::verdict($this->checks()->suppressionTokens()));
    }

    public function test_passes_a_name_that_only_starts_with_a_token(): void
    {
        $this->write('resources/js/lib/sample.test.ts', "import { expect, it } from 'vite-plus/test';\n\nit('a', () => {\n    expect({ skipped: 1 }.skipped).toBe(1);\n});\n");

        $this->assertClean(self::verdict($this->checks()->suppressionTokens()));
    }

    public function test_detects_an_argument_added_to_the_script(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT.' resources/js/lib', Verification::VITE_TEST_RUN);

        $this->assertDetects('script', Verification::VITE_TEST_SCRIPT_NAME.' = '.Verification::VITE_TEST_SCRIPT.' resources/js/lib', null, self::verdict($this->checks()->scripts()));
    }

    public function test_detects_an_argument_added_to_the_call_in_scripts_ci(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, Verification::VITE_TEST_RUN.' -- -t 単体');

        $this->assertDetects('test:unit.arguments', 'scripts/ci/front', 3, self::verdict($this->checks()->scripts()));
    }

    public function test_detects_a_direct_run_of_vp_test_in_scripts_ci(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, 'npx vp test resources/js/lib');

        $this->assertDetects('vp-test.direct', 'scripts/ci/front', 3, self::verdict($this->checks()->scripts()));
    }

    public function test_detects_a_quoted_argument_added_to_the_call_in_scripts_ci(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, Verification::VITE_TEST_RUN.' "resources/js/lib"');

        $this->assertDetects('test:unit.arguments', 'scripts/ci/front', 3, self::verdict($this->checks()->scripts()));
    }

    public function test_detects_scripts_ci_front_without_the_call(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, 'npm run check');

        $this->assertDetects('test:unit.call', Verification::VITE_TEST_CALLER, null, self::verdict($this->checks()->scripts()));
    }

    public function test_detects_scripts_ci_front_calling_it_twice(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, Verification::VITE_TEST_RUN."\n".Verification::VITE_TEST_RUN);

        $this->assertDetects('test:unit.call', Verification::VITE_TEST_CALLER, null, self::verdict($this->checks()->scripts()));
    }

    public function test_detects_vp_test_in_another_package_script(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, Verification::VITE_TEST_RUN, ['test:fast' => 'vp test run resources/js/lib']);

        $this->assertDetects('script.direct', 'test:fast = vp test run resources/js/lib', null, self::verdict($this->checks()->scripts()));
    }

    public function test_passes_the_call_and_the_words_in_comments_and_messages(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, "# 画面の単体テスト(vp test)\necho \"==> 画面の単体テスト(vp test)\"\necho \"次は npm run test:unit -- を使わない\"\n".Verification::VITE_TEST_RUN, ['echo:hint' => 'echo "vp test"']);

        $this->assertClean(self::verdict($this->checks()->scripts()));
    }

    public function test_passes_a_comment_in_a_non_shell_file_in_scripts_ci(): void
    {
        $this->writeScripts(Verification::VITE_TEST_SCRIPT, Verification::VITE_TEST_RUN);
        $this->write('scripts/ci/helper.php', "<?php\n// vp test と test:unit を説明するコメント\n");

        $this->assertClean(self::verdict($this->checks()->scripts()));
    }

    public function test_detects_a_narrowed_include(): void
    {
        $this->writeViteConfig("{ include: ['resources/js/lib/**/*.test.ts'] }");

        $this->assertDetects('test.include.missing', 'resources/js/**/*.test.ts', null, self::verdict($this->checks()->targets()));
    }

    public function test_detects_a_widened_include(): void
    {
        $this->writeViteConfig("{ include: ['resources/js/**/*.test.ts', 'tests/**/*.test.ts'] }");

        $this->assertDetects('test.include.extra', 'tests/**/*.test.ts', null, self::verdict($this->checks()->targets()));
    }

    public function test_detects_a_test_section_without_include(): void
    {
        $this->writeViteConfig("{ env: { TZ: 'UTC' } }");

        $this->assertDetects('test.include.missing', 'resources/js/**/*.test.ts', null, self::verdict($this->checks()->targets()));
    }

    public function test_detects_a_key_outside_the_policy(): void
    {
        $this->writeViteConfig("{ include: ['resources/js/**/*.test.ts'], exclude: ['resources/js/lib/**'] }");

        $this->assertDetects('test.key', 'exclude', null, self::verdict($this->checks()->targets()));
    }

    public function test_detects_a_vitest_config_read_before_vite_config(): void
    {
        $this->writeViteConfig("{ include: ['resources/js/**/*.test.ts'] }");
        $this->write('vitest.config.ts', "export default { test: { include: ['resources/js/lib/**/*.test.ts'] } };\n");

        $this->assertDetects('config.file', 'vitest.config.ts', null, self::verdict($this->checks()->targets()));
    }

    public function test_detects_a_vite_config_in_another_extension_read_before_vite_config_ts(): void
    {
        $this->writeViteConfig("{ include: ['resources/js/**/*.test.ts'] }");
        $this->write('vite.config.js', "export default { test: { include: ['resources/js/lib/**/*.test.ts'] } };\n");

        $this->assertDetects('config.file', 'vite.config.js', null, self::verdict($this->checks()->targets()));
    }

    public function test_passes_the_include_and_the_keys_of_the_policy(): void
    {
        $this->writeViteConfig("{ include: ['resources/js/**/*.test.ts'], env: { TZ: 'UTC' } }");

        $this->assertClean(self::verdict($this->checks()->targets()));
    }

    private function checks(): ViteTestChecks
    {
        return new ViteTestChecks(self::appRoot(), $this->workRoot, self::config('guardrails_allow')['exclusions'] ?? null);
    }

    private function writeViteConfig(string $test): void
    {
        $this->write(Verification::VITE_CONFIG, "export default { test: {$test} };\n");
    }

    /**
     * package.json の test:unit の script($others はほかの script)と、scripts/ci/front の本文($body。3 行目から)。
     *
     * @param  array<string, string>  $others
     */
    private function writeScripts(string $script, string $body, array $others = []): void
    {
        $this->write(Verification::PACKAGE_JSON, json_encode(['scripts' => [Verification::VITE_TEST_SCRIPT_NAME => $script, ...$others]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->write(Verification::SCRIPTS_DIRECTORY.'/front', "#!/usr/bin/env bash\nset -euo pipefail\n{$body}\n");
    }

    private function write(string $path, string $content): void
    {
        if (! is_dir(dirname($this->workRoot.'/'.$path))) {
            mkdir(dirname($this->workRoot.'/'.$path), 0777, true);
        }

        file_put_contents($this->workRoot.'/'.$path, $content);
    }
}
