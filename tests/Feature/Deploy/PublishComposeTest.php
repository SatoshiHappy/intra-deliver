<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * 公開用の compose.publish.yml の守り(docs の 設計/公開の配備/構成/README.md の 3.1・3.2.2 節、構成/設定.md の 3.4・3.5 節): 強化と再起動・ports: を書かない・
 * 外へ出られるのは tunnel と queue だけ・AI の接続先の鍵は queue だけ・queue と scheduler は app のイメージ・queue の止め方の待ち・
 * scheduler のメモリの上限(3.2.3 節)。
 * 本物の docker は呼ばない(起こして確かめるのは実機の検証)。
 */
final class PublishComposeTest extends TestCase
{
    public function test_every_service_is_hardened_restarts_and_publishes_no_port(): void
    {
        foreach ($this->services() as $name => $service) {
            $this->assertTrue($service['read_only'] ?? null, $name);
            $this->assertSame(['ALL'], $service['cap_drop'] ?? null, $name);
            $this->assertSame(['no-new-privileges:true'], $service['security_opt'] ?? null, $name);
            $this->assertSame('unless-stopped', $service['restart'] ?? null, $name);
            $this->assertArrayNotHasKey('ports', $service, $name);
        }
    }

    /**
     * どのサービスも networks: を書く(書かないと既定のネットワーク = 外へ出られるものに属す)。最上位で宣言していないネットワークと
     * internal でないネットワークを外へ出られるものと数える。networks: はリストと map の両方の形を読む。
     */
    public function test_only_the_tunnel_and_the_queue_reach_outside(): void
    {
        $declared = $this->compose()['networks'] ?? [];
        $this->assertIsArray($declared);
        $reaching = [];

        foreach ($this->services() as $name => $service) {
            $this->assertArrayHasKey('networks', $service, "{$name} が networks: を書いていない");
            $networks = array_is_list($service['networks']) ? $service['networks'] : array_keys($service['networks']);
            $internal = array_filter($networks, fn (string $network): bool => self::internal($declared, $network));
            $reaching = count($internal) === count($networks) ? $reaching : [...$reaching, $name];
        }

        $this->assertEqualsCanonicalizing(['tunnel', 'queue'], $reaching);
    }

    /** env_file は文字列・リスト・長い書式(path)の 3 つの形を読む */
    public function test_only_the_queue_receives_the_ai_keys(): void
    {
        $receiving = array_keys(array_filter(
            $this->services(),
            fn (array $service): bool => preg_grep('#/ai\.env\z#', self::envFiles($service['env_file'] ?? [])) !== [],
        ));

        $this->assertSame(['queue'], $receiving);
    }

    public function test_the_queue_and_the_scheduler_run_the_app_image_without_building(): void
    {
        $services = $this->services();

        $this->assertArrayHasKey('build', $services['app']);

        foreach (['queue', 'scheduler'] as $name) {
            $this->assertSame($services['app']['image'], $services[$name]['image'] ?? null, $name);
            $this->assertArrayNotHasKey('build', $services[$name], $name);
            $this->assertTrue($services[$name]['init'] ?? null, "{$name} は子のプロセスを起こすので init を PID 1 にする");
        }
    }

    /**
     * 止めるときに処理中のジョブを終えるまで待つ時間は、キューが配り直すまでの時間(ジョブの時間の上限より長い = docs の 設計/AI/実行と費用.md の
     * 4.1 節)以上。キューが配り直すまでの時間は config/queue.php の既定値だけが決める(雛形に置かない)。
     */
    public function test_the_queue_waits_for_the_running_job_at_least_as_long_as_the_queue_redelivers(): void
    {
        $queue = (string) file_get_contents(config_path('queue.php'));
        $grace = $this->number('/^(\d+)s$/D', (string) ($this->services()['queue']['stop_grace_period'] ?? ''));
        $default = $this->number("/env\\('DB_QUEUE_RETRY_AFTER', (\\d+)\\)/", $queue);

        $this->assertGreaterThanOrEqual($default, $grace);
    }

    /**
     * scheduler のメモリの上限 = 毎日の初期化の間に同時に動くプロセスの最大 5 つ × memory_limit(php.ini の値。scheduler の子のプロセスには
     * 起動のコマンドの -d が効かない)+ 余白 96MB = 576MB(docs の 設計/公開の配備/構成/README.md 3.2.3)。
     */
    public function test_the_scheduler_memory_limit_covers_five_processes_during_the_daily_reset(): void
    {
        $phpIni = parse_ini_file(base_path('docker/app/php.ini'), false, INI_SCANNER_RAW);
        $this->assertIsArray($phpIni);
        $memoryLimit = $this->number('/^(\d+)M$/D', (string) ($phpIni['memory_limit'] ?? ''));
        $schedulerLimit = $this->number('/^(\d+)m$/D', (string) ($this->services()['scheduler']['mem_limit'] ?? ''));

        $this->assertSame(96, $memoryLimit);
        $this->assertSame(576, $schedulerLimit);
        $this->assertSame(5 * $memoryLimit + 96, $schedulerLimit);
    }

    /**
     * 最上位で宣言した internal: true のネットワークか(宣言の外は外へ出られるものと数える)。
     *
     * @param  array<mixed>  $declared
     */
    private static function internal(array $declared, string $network): bool
    {
        return is_array($declared[$network] ?? null) && ($declared[$network]['internal'] ?? false) === true;
    }

    /**
     * env_file の形(文字列・リスト・長い書式の path)からファイルの一覧。
     *
     * @return list<string>
     */
    private static function envFiles(mixed $envFile): array
    {
        return array_map(
            fn (mixed $entry): string => (string) (is_array($entry) ? ($entry['path'] ?? '') : $entry),
            is_array($envFile) ? array_values($envFile) : [$envFile],
        );
    }

    /** $subject の中の $pattern の 1 つ目の組の数(一致しなければ落とす) */
    private function number(string $pattern, string $subject): int
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches), "{$pattern} に一致しない: {$subject}");

        return (int) $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(): array
    {
        $compose = Yaml::parseFile(base_path('compose.publish.yml'));

        $this->assertIsArray($compose);

        return $compose;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(): array
    {
        $services = $this->compose()['services'];

        $this->assertIsArray($services);
        $this->assertSame(['app', 'db', 'queue', 'scheduler', 'tunnel'], array_keys($services));

        return $services;
    }
}
