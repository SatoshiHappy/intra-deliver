<?php

namespace Tests\Feature\Deploy;

use App\Queries\Ai\TranscriptionSettings;
use Tests\TestCase;

/**
 * 公開用の app の php.ini(docker/app/php.ini。docs の 設計/公開の配備/構成/設定.md 3.6)の 1 要求の時間の上限と、毎日の初期化が
 * メンテナンスの状態にした後に要求を待つ秒(docs の 設計/公開の配備/毎日の初期化.md 2 節の手順 3)・要求の中で走る音声の測りの
 * 時間の上限(docs の 設計/AI/実行と費用.md 4.2)の大小。効くかは実機検証で見る。
 */
final class PublishPhpIniTest extends TestCase
{
    public function test_the_request_time_limit_is_the_production_value(): void
    {
        $this->assertSame('30', $this->phpIni()['max_execution_time'] ?? null);
    }

    public function test_the_daily_reset_waits_longer_than_one_request_may_run(): void
    {
        $limit = (int) ($this->phpIni()['max_execution_time'] ?? 0);

        $this->assertGreaterThan(0, $limit);
        $this->assertGreaterThan($limit, config('reset.request_wait_seconds'));
    }

    public function test_the_audio_measure_limit_is_shorter_than_the_request_time_limit(): void
    {
        $limit = (int) ($this->phpIni()['max_execution_time'] ?? 0);
        // 外部のプロセスは時間切れを確かめて止めるまでの分だけ測りの上限を超えうるので、余白を足して比べる(設計/AI/実行と費用.md 4.2)
        $worst = app(TranscriptionSettings::class)->measureTimeoutSeconds() + 2;

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThan($limit, $worst);
    }

    /**
     * @return array<string, string>
     */
    private function phpIni(): array
    {
        $values = parse_ini_file(base_path('docker/app/php.ini'), false, INI_SCANNER_RAW);

        $this->assertIsArray($values);

        return array_map(fn (mixed $value): string => (string) $value, $values);
    }
}
