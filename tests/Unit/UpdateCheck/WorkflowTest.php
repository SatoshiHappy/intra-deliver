<?php

namespace Tests\Unit\UpdateCheck;

use Symfony\Component\Yaml\Yaml;

/**
 * .github/workflows/update-check.yml の守り(方針 2・3 節): 時期・権限・時間切れ・Actions のコミット SHA での固定・式をシェルに埋め込まない。
 */
final class WorkflowTest extends UpdateCheckTestCase
{
    private const FILE = '.github/workflows/update-check.yml';

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        $workflow = Yaml::parseFile(self::root().'/'.self::FILE);

        $this->assertIsArray($workflow);

        return $workflow;
    }

    /**
     * @return array<string, mixed>
     */
    private function job(string $name): array
    {
        $jobs = $this->workflow()['jobs'];

        $this->assertIsArray($jobs);
        $this->assertIsArray($jobs[$name]);

        return $jobs[$name];
    }

    public function test_3_日に_1_回の_cron_と手動の実行(): void
    {
        $on = $this->workflow()['on'];

        $this->assertIsArray($on);
        $this->assertSame([['cron' => '17 0 */3 * *']], $on['schedule']);
        $this->assertArrayHasKey('workflow_dispatch', $on);
    }

    public function test_権限は既定が無しで_調べるジョブは_contents_read_書くジョブは_issues_write(): void
    {
        $this->assertSame([], $this->workflow()['permissions']);
        $this->assertSame(['contents' => 'read'], $this->job('check')['permissions']);
        $this->assertSame(['issues' => 'write'], $this->job('report')['permissions']);
    }

    public function test_時間切れは調べるジョブ_30_分_書くジョブ_5_分(): void
    {
        $this->assertSame(30, $this->job('check')['timeout-minutes']);
        $this->assertSame(5, $this->job('report')['timeout-minutes']);
    }

    public function test_書くジョブは調べるジョブを待ち結果によらず動く(): void
    {
        $this->assertSame('check', $this->job('report')['needs']);
        $this->assertSame('${{ always() }}', $this->job('report')['if']);
    }

    public function test_書くジョブは_checkout_も依存の取得もしない(): void
    {
        $steps = $this->job('report')['steps'];

        $this->assertIsArray($steps);

        $uses = array_values(array_filter(array_map(static fn (mixed $step): mixed => is_array($step) ? ($step['uses'] ?? null) : null, $steps)));

        $this->assertCount(1, $uses);
        $this->assertStringStartsWith('actions/download-artifact@', (string) $uses[0]);
    }

    public function test_checkout_は認証情報を残さない(): void
    {
        $steps = $this->job('check')['steps'];

        $this->assertIsArray($steps);
        $this->assertIsArray($steps[0]);
        $this->assertStringStartsWith('actions/checkout@', (string) $steps[0]['uses']);
        $this->assertFalse($steps[0]['with']['persist-credentials']);
    }

    public function test_外から取る_actions_はコミット_sha_で固定し_タグ名を添える(): void
    {
        $text = (string) file_get_contents(self::root().'/'.self::FILE);

        preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/m', $text, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches);

        foreach ($matches as $match) {
            $this->assertMatchesRegularExpression('#^[\w.-]+/[\w.-]+@[0-9a-f]{40}$#D', $match[1]);
            $this->assertMatchesRegularExpression('/^\s+# v\d+\.\d+\.\d+$/D', $match[2]);
        }
    }

    public function test_シークレットを使わず_run_に式を埋め込まない(): void
    {
        $text = (string) file_get_contents(self::root().'/'.self::FILE);

        $this->assertStringNotContainsString('secrets.', $text);

        foreach (['check', 'report'] as $name) {
            $steps = $this->job($name)['steps'];

            $this->assertIsArray($steps);

            foreach ($steps as $step) {
                $this->assertIsArray($step);
                $this->assertStringNotContainsString('${{', (string) ($step['run'] ?? ''));
            }
        }
    }

    public function test_書くジョブは受け取りの段の_outcome_を_env_で渡して見る(): void
    {
        $steps = $this->job('report')['steps'];

        $this->assertIsArray($steps);
        $this->assertSame('download', $steps[0]['id']);
        $this->assertTrue($steps[0]['continue-on-error']);

        $publish = array_values(array_filter($steps, static fn (mixed $step): bool => is_array($step) && ($step['name'] ?? '') === 'Issue に書く'));

        // conclusion は continue-on-error の段でも success になるので使わない
        $this->assertSame('${{ steps.download.outcome }}', $publish[0]['env']['DOWNLOAD_OUTCOME']);
        $this->assertStringContainsString('"$DOWNLOAD_OUTCOME" = success', (string) $publish[0]['run']);
        $this->assertStringNotContainsString('steps.download.conclusion', (string) file_get_contents(self::root().'/'.self::FILE));
    }

    public function test_npm_ci_はワークフローの段にせず_node_は_setup_node_で固定する(): void
    {
        $steps = $this->job('check')['steps'];

        $this->assertIsArray($steps);

        foreach ($steps as $step) {
            $this->assertIsArray($step);
            $this->assertStringNotContainsString('npm ci', (string) ($step['run'] ?? ''));
        }

        $this->assertStringStartsWith('actions/setup-node@', (string) $steps[1]['uses']);
        $this->assertSame('22', $steps[1]['with']['node-version']);
    }
}
