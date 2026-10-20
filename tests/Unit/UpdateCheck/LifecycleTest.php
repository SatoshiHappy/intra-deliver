<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\RuntimeVersions;
use UpdateCheck\ScanOutcome;
use UpdateCheck\ScanResult;
use UpdateCheck\Status;
use UpdateCheck\Unconfirmed;

/**
 * #6 実行の本体のパッチ版・#7 サポート期限と新しい系列・#8 compatibility_date。今日は 2026-10-15 に固定する。
 */
final class LifecycleTest extends LifecycleTestCase
{
    public function test_期限が遠く新しい系列が無ければ表示だけ_新しい系列があれば更新あり(): void
    {
        $section = $this->lifecycle($this->eol($this->products()));

        $this->assertSame([], $section->unconfirmed);
        $this->assertSame(Status::Update, $this->statuses($section)['PHP'][0]);
        $this->assertSame('8.5', $section->rows[0]->cells[4]);
        $this->assertSame(Status::Info, $this->statuses($section)['Laravel'][0]);
        $this->assertSame(['React', '19', '(なし)', '-', '(なし)'], $section->rows[3]->cells);
        $this->assertSame(Status::Update, $this->statuses($section)['PostgreSQL'][0]);
        $this->assertSame(Status::Update, $this->statuses($section)['Alpine(db)'][0]);
        $this->assertSame(Status::Info, $this->statuses($section)['Debian(app)'][0]);
    }

    public function test_公開前の系列は新しい系列に数えない(): void
    {
        $products = $this->products();
        $products['postgresql'] = $this->product([['19', '2026-11-30', '2031-11-12', false, '19.0'], ['18', '2025-09-25', '2030-11-14', false, '18.6']]);

        $this->assertSame(Status::Info, $this->statuses($this->lifecycle($this->eol($products)))['PostgreSQL'][0]);
    }

    public function test_期限まで_90_日以内は要対応_91_日は要対応でない(): void
    {
        $products = $this->products();
        $products['nodejs'] = $this->product([['22', '2024-04-24', '2027-01-13', false, '22.23.3']]);
        $products['laravel'] = $this->product([['13', '2026-03-17', '2027-01-14', false, '13.36.0']]);

        $statuses = $this->statuses($this->lifecycle($this->eol($products)));

        $this->assertSame([Status::Action, 'あと 90 日'], $statuses['Node.js']);
        $this->assertSame([Status::Info, 'あと 91 日'], $statuses['Laravel']);
    }

    public function test_期限切れは要対応(): void
    {
        $products = $this->products();
        $products['nodejs'] = $this->product([['22', '2024-04-24', '2026-10-01', true, '22.23.3']]);

        $this->assertSame([Status::Action, '期限切れ'], $this->statuses($this->lifecycle($this->eol($products)))['Node.js']);
    }

    public function test_系列が_endoflife_date_に無ければその製品だけ確認できなかった(): void
    {
        $products = $this->products();
        $products['laravel'] = $this->product([['12', '2025-02-24', '2027-02-24', false, '12.70.0']]);

        $section = $this->lifecycle($this->eol($products));

        $this->assertSame(['Laravel'], array_map(static fn (Unconfirmed $u): string => $u->subject, $section->unconfirmed));
        $this->assertCount(6, $section->rows);
    }

    public function test_5_が確認できなければ_その画像の_os_の行を確認できなかったにし_postgresql_は_6_の理由を使う(): void
    {
        $scan = new ScanOutcome([], [new Unconfirmed('app', 'x'), new Unconfirmed('db', 'y')]);
        $runtime = new RuntimeVersions([], ['PostgreSQL' => '#5 が確認できなかった']);

        $section = $this->lifecycle($this->eol($this->products()), $scan, $runtime);

        $this->assertSame(['PostgreSQL', 'OS(app)', 'OS(db)'], array_map(static fn (Unconfirmed $u): string => $u->subject, $section->unconfirmed));
        $this->assertCount(4, $section->rows);
    }

    public function test_tunnel_だけの失敗では_app_と_db_の_os_の行は出て_tunnel_の_os_だけ確認できなかった(): void
    {
        $scan = new ScanOutcome([new ScanResult('app', [], 'debian', '13.1'), new ScanResult('db', [], 'alpine', '3.22.1')], [new Unconfirmed('tunnel', 'x')]);

        $section = $this->lifecycle($this->eol($this->products()), $scan);

        $this->assertSame(['OS(tunnel)'], array_map(static fn (Unconfirmed $u): string => $u->subject, $section->unconfirmed));
        $this->assertArrayHasKey('Debian(app)', $this->statuses($section));
        $this->assertArrayHasKey('Alpine(db)', $this->statuses($section));
        $this->assertSame('#5 が確認できなかった', $section->unconfirmed[0]->reason);
    }

    public function test_os_を判定できないイメージは黙って外さず_そのイメージの_os_を確認できなかったにする(): void
    {
        $scan = new ScanOutcome([new ScanResult('app', [], 'debian', '13.1'), new ScanResult('db', [], '', ''), new ScanResult('tunnel', [], 'wolfi', '20230201')], []);

        $section = $this->lifecycle($this->eol($this->products()), $scan);

        $this->assertSame(['OS(db)', 'OS(tunnel)'], array_map(static fn (Unconfirmed $u): string => $u->subject, $section->unconfirmed));
        $this->assertSame(['Trivy が OS を読めなかった', '対応表に無い OS: wolfi'], array_map(static fn (Unconfirmed $u): string => $u->reason, $section->unconfirmed));
        $this->assertArrayHasKey('Debian(app)', $this->statuses($section));
    }
}
