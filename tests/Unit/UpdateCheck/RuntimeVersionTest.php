<?php

namespace Tests\Unit\UpdateCheck;

use DateTimeImmutable;
use DateTimeZone;
use UpdateCheck\CommandResult;
use UpdateCheck\ImageScanner;
use UpdateCheck\RuntimeVersionCheck;
use UpdateCheck\RuntimeVersionReader;
use UpdateCheck\RuntimeVersions;
use UpdateCheck\ScanOutcome;
use UpdateCheck\Status;
use UpdateCheck\Unconfirmed;
use UpdateCheck\WorkerDateCheck;

/**
 * #6 実行の本体のパッチ版・#7 サポート期限と新しい系列・#8 compatibility_date。今日は 2026-10-15 に固定する。
 */
final class RuntimeVersionTest extends LifecycleTestCase
{
    public function test_6_はイメージの中の版が系列の最新より古ければ更新あり(): void
    {
        $eol = $this->eol($this->products());

        $section = (new RuntimeVersionCheck(new RuntimeVersions(['PHP' => '8.4.25', 'PostgreSQL' => '18.6'], []), $eol))->run();

        $this->assertSame([Status::Update, Status::Info], array_map(static fn ($row): Status => $row->status, $section->rows));
        $this->assertSame(['PHP', '8.4.25', '8.4.26'], $section->rows[0]->cells);
    }

    public function test_6_は_使うイメージの_5_が確認できなければ確認できなかった(): void
    {
        $reader = new RuntimeVersionReader(new FakeCommandRunner([]), '/x', self::budget());

        $runtime = $reader->read(new ScanOutcome([], [new Unconfirmed('app', 'x'), new Unconfirmed('db', 'y')]));
        $section = (new RuntimeVersionCheck($runtime, $this->eol([])))->run();

        $this->assertSame(['PHP', 'PostgreSQL'], array_map(static fn (Unconfirmed $u): string => $u->subject, $section->unconfirmed));
        $this->assertSame('#5 が確認できなかった', $section->unconfirmed[0]->reason);
    }

    public function test_tunnel_だけの失敗では_6_の_php_と_postgresql_は読める(): void
    {
        $runner = new FakeCommandRunner([
            'docker run --rm --entrypoint php '.ImageScanner::APP_IMAGE.' -r echo PHP_VERSION;' => new CommandResult(0, '8.4.26', ''),
            'docker run --rm --entrypoint postgres '.ImageScanner::DB_IMAGE.' --version' => new CommandResult(0, 'postgres (PostgreSQL) 18.4', ''),
        ]);

        $runtime = (new RuntimeVersionReader($runner, '/x', self::budget()))->read(new ScanOutcome([], [new Unconfirmed('tunnel', 'x')]));

        $this->assertSame(['PHP' => '8.4.26', 'PostgreSQL' => '18.4'], $runtime->versions);
        $this->assertSame([], $runtime->reasons);
    }

    public function test_app_だけの失敗では_postgresql_は読める(): void
    {
        $runner = new FakeCommandRunner([
            'docker run --rm --entrypoint postgres '.ImageScanner::DB_IMAGE.' --version' => new CommandResult(0, 'postgres (PostgreSQL) 18.4', ''),
        ]);

        $runtime = (new RuntimeVersionReader($runner, '/x', self::budget()))->read(new ScanOutcome([], [new Unconfirmed('app', 'x')]));

        $this->assertSame(['PostgreSQL' => '18.4'], $runtime->versions);
        $this->assertSame(['PHP'], array_keys($runtime->reasons));
    }

    public function test_版の読み取りは_5_で作ったイメージの中でコマンドを流す(): void
    {
        $runner = new FakeCommandRunner([
            'docker run --rm --entrypoint php '.ImageScanner::APP_IMAGE.' -r echo PHP_VERSION;' => new CommandResult(0, '8.4.26', ''),
            'docker run --rm --entrypoint postgres '.ImageScanner::DB_IMAGE.' --version' => new CommandResult(0, "postgres (PostgreSQL) 18.4\n", ''),
        ]);

        $runtime = (new RuntimeVersionReader($runner, '/x', self::budget()))->read(new ScanOutcome([], []));

        $this->assertSame(['PHP' => '8.4.26', 'PostgreSQL' => '18.4'], $runtime->versions);
        $this->assertSame([], $runtime->reasons);
    }

    public function test_版を読めなければその本体だけ確認できなかった(): void
    {
        $runner = new FakeCommandRunner([
            'docker run --rm --entrypoint php '.ImageScanner::APP_IMAGE.' -r echo PHP_VERSION;' => new CommandResult(0, 'PHP Warning: something', ''),
            'docker run --rm --entrypoint postgres '.ImageScanner::DB_IMAGE.' --version' => new CommandResult(0, 'postgres (PostgreSQL) 18.4', ''),
        ]);

        $runtime = (new RuntimeVersionReader($runner, '/x', self::budget()))->read(new ScanOutcome([], []));

        $this->assertSame(['PostgreSQL' => '18.4'], $runtime->versions);
        $this->assertSame(['PHP'], array_keys($runtime->reasons));
    }

    public function test_8_は_compatibility_date_からの経過日数を表示だけで出す(): void
    {
        $section = (new WorkerDateCheck($this->root, new DateTimeImmutable('2026-10-15', new DateTimeZone('UTC'))))->run();

        $this->assertSame(Status::Info, $section->rows[0]->status);
        $this->assertSame(['deploy/worker/wrangler.jsonc', '2026-09-25', '20'], $section->rows[0]->cells);
    }

    public function test_8_は日付を読めなければ確認できなかった(): void
    {
        file_put_contents($this->root.'/deploy/worker/wrangler.jsonc', '{}');

        $this->assertCount(1, (new WorkerDateCheck($this->root, new DateTimeImmutable('2026-10-15', new DateTimeZone('UTC'))))->run()->unconfirmed);
    }
}
