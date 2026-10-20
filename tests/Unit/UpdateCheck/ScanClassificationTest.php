<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\ImageScanCheck;
use UpdateCheck\ScanOutcome;
use UpdateCheck\ScanResult;
use UpdateCheck\Status;
use UpdateCheck\TrivyReport;
use UpdateCheck\Unconfirmed;
use UpdateCheck\Vulnerability;

/**
 * #5: Trivy の JSON を読んで区分する(修正版があれば要対応、無ければ修正版なし)。構築や検査が確認できなければ、その部分だけ確認できなかった。
 */
final class ScanClassificationTest extends UpdateCheckTestCase
{
    private function vuln(string $id, string $package, ?string $fixed, string $severity = 'HIGH'): Vulnerability
    {
        return new Vulnerability($id, $package, '1', $fixed, $severity);
    }

    /**
     * @param  list<Vulnerability>  $vulnerabilities
     */
    private function scanResult(string $image, array $vulnerabilities): ScanResult
    {
        return new ScanResult($image, $vulnerabilities, 'debian', '13.1');
    }

    public function test_新しい土台に同じ脆弱性が無ければ要対応_残るものは土台で未修正に分類する(): void
    {
        $pinned = $this->scanResult('app', [
            $this->vuln('CVE-1', 'liba', '2'),
            $this->vuln('CVE-2', 'libb', '2'),
            $this->vuln('CVE-3', 'libc', null),
            $this->vuln('CVE-1', 'libother', '2'),
        ]);
        $new = $this->scanResult('app', [$this->vuln('CVE-2', 'libb', '2'), $this->vuln('CVE-3', 'libc', null), $this->vuln('CVE-9', 'libz', '1')]);

        $section = (new ImageScanCheck(new ScanOutcome([$pinned], [], ['app' => $new])))->run();

        $this->assertSame(
            [['上げれば直る', Status::Action], ['上げれば直る', Status::Action], ['修正版はあるが新しい土台でも残る', Status::Unfixed], ['修正版なし', Status::Unfixed]],
            array_map(static fn ($row): array => [$row->cells[1], $row->status], $section->rows),
        );
        $this->assertSame(['CVE-1', 'CVE-1', 'CVE-2', 'CVE-3'], array_map(static fn ($row): string => $row->cells[3], $section->rows));
    }

    public function test_同じ_id_でもパッケージが違えば同じ脆弱性として扱わない(): void
    {
        $pinned = $this->scanResult('app', [$this->vuln('CVE-1', 'liba', '2')]);
        $new = $this->scanResult('app', [$this->vuln('CVE-1', 'libb', '2')]);

        $section = (new ImageScanCheck(new ScanOutcome([$pinned], [], ['app' => $new])))->run();

        $this->assertSame(Status::Action, $section->rows[0]->status);
    }

    public function test_新しい土台が無いイメージの脆弱性は新しい土台なしで数える(): void
    {
        $pinned = $this->scanResult('db', [$this->vuln('CVE-1', 'liba', '2'), $this->vuln('CVE-2', 'libb', null)]);

        $section = (new ImageScanCheck(new ScanOutcome([$pinned], [])))->run();

        $this->assertSame(['新しい土台なし', '新しい土台なし'], array_map(static fn ($row): string => $row->cells[1], $section->rows));
        $this->assertSame([Status::Unfixed, Status::Unfixed], array_map(static fn ($row): Status => $row->status, $section->rows));
    }

    public function test_新しい土台の失敗は確認できなかった_新しい土台として出る(): void
    {
        $pinned = $this->scanResult('app', [$this->vuln('CVE-1', 'liba', '2')]);

        $section = (new ImageScanCheck(new ScanOutcome([$pinned], [], [], ['app' => new Unconfirmed('app(新しい土台)', 'x')])))->run();

        $this->assertSame('app(新しい土台)', $section->unconfirmed[0]->subject);
        $this->assertSame(Status::Unfixed, $section->rows[0]->status);
    }

    public function test_脆弱性が無いイメージは表示だけの行を出す(): void
    {
        $outcome = new ScanOutcome([TrivyReport::parse('{"SchemaVersion":2}', 'db')], []);

        $section = (new ImageScanCheck($outcome))->run();

        $this->assertSame(Status::Info, $section->rows[0]->status);
    }

    public function test_新しい土台はあるが検査できなかった画像は新しい土台の検査が確認できなかったに分類し_要対応にしない(): void
    {
        $pinned = $this->scanResult('app', [$this->vuln('CVE-1', 'liba', '2'), $this->vuln('CVE-2', 'libb', null)]);

        $section = (new ImageScanCheck(new ScanOutcome([$pinned], [], [], ['app' => new Unconfirmed('app(新しい土台)', 'x')])))->run();

        $this->assertSame(['新しい土台の検査が確認できなかった', '新しい土台の検査が確認できなかった'], array_map(static fn ($row): string => $row->cells[1], $section->rows));
        $this->assertSame([Status::Unfixed, Status::Unfixed], array_map(static fn ($row): Status => $row->status, $section->rows));
        $this->assertCount(1, $section->unconfirmed);
    }

    public function test_unverified_は_新しい土台の失敗と_4_の照会の失敗から導く(): void
    {
        $outcome = new ScanOutcome([], [], [], ['app' => new Unconfirmed('app(新しい土台)', 'x'), 'db' => new Unconfirmed('db(新しい土台)', 'y')], ['db', 'tunnel']);

        $this->assertSame(['app', 'db', 'tunnel'], $outcome->unverified());
        $this->assertSame([], (new ScanOutcome([], []))->unverified());
    }
}
