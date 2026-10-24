<?php

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsDeployScripts;
use Tests\TestCase;

/**
 * DB に流す scripts/deploy/migrate-and-seed の空の DB の検査(docs の 設計/公開の配備/配備と戻し方.md 6.2 の 6〜7・6.7)を、偽の docker で確かめる。
 * 本物の docker は呼ばない。道具は {@see RunsDeployScripts}。
 */
final class MigrateAndSeedScriptTest extends TestCase
{
    use RunsDeployScripts;

    /** 止まった後の案内(配備と戻し方 6.7 の、途中で失敗した後に DB を空にしてから流し直す項を指す) */
    private const RETRY_GUIDANCE = '配備と戻し方 6.7 の「migrate-and-seed が途中で失敗した後」';

    /**
     * (f) 表が無い(migrate:status が失敗し、出力に Migration table not found がある)ときは、マイグレーションと初期データ
     * (毎日の初期化と同じ並びで入れる reset:seed の 1 回)が順に流れる。
     */
    public function test_migrate_and_seed_runs_everything_when_the_migrations_table_is_absent(): void
    {
        $this->installDocker([['migrate:status', 1, 'ERROR  Migration table not found.']]);

        $result = $this->runMigrateAndSeed();

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('Migration table not found', $result->stdout);

        $subcommands = $this->subcommandsCalled();
        $this->assertSame([
            'run --rm --no-deps -T app php artisan migrate:status --no-interaction',
            'run --rm --no-deps -T app php artisan migrate --force --no-interaction',
            'run --rm --no-deps -T app php artisan reset:seed --no-interaction',
        ], $subcommands);
    }

    /**
     * (g) migrations 表がある(migrate:status が成功する)ときは、終了 1 で migrate を流さない。
     */
    public function test_migrate_and_seed_stops_when_the_migrations_table_exists(): void
    {
        $this->installDocker([['migrate:status', 0, 'Ran  2024_01_01_000000_create_x_table']]);

        $result = $this->runMigrateAndSeed();

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertCount(1, $this->calls());
        $this->assertStringContainsString('migrate:status', $this->calls()[0]);
        $this->assertStringContainsString(self::RETRY_GUIDANCE, $result->stderr);
    }

    /**
     * (h) migrate:status が表の無さ以外の理由で失敗する(接続の失敗など)ときは、終了 1 で migrate を流さない。
     */
    public function test_migrate_and_seed_stops_when_the_state_cannot_be_determined(): void
    {
        $this->installDocker([['migrate:status', 1, 'SQLSTATE[08006] connection refused']]);

        $result = $this->runMigrateAndSeed();

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertCount(1, $this->calls());
        $this->assertStringContainsString('SQLSTATE', $result->stdout);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function failingStepsAfterTheCheck(): array
    {
        return [
            'migrate' => ['migrate --force', 2],
            'initial data' => ['reset:seed', 3],
        ];
    }

    /**
     * (i) 途中の 1 つが失敗したら、そこで止まり、以降を流さない。DB を空にしてから流し直す案内を出す。
     */
    #[DataProvider('failingStepsAfterTheCheck')]
    public function test_migrate_and_seed_stops_at_the_failing_step(string $failingNeedle, int $expectedCalls): void
    {
        $this->installDocker([
            ['migrate:status', 1, 'ERROR  Migration table not found.'],
            [$failingNeedle, 1, 'boom'],
        ]);

        $result = $this->runMigrateAndSeed();

        $this->assertSame(1, $result->exitCode, $result->describe());
        $this->assertCount($expectedCalls, $this->calls());
        $this->assertStringContainsString(self::RETRY_GUIDANCE, $result->stderr);
    }
}
