<?php

namespace Tests\Unit\UpdateCheck;

use DateTimeImmutable;
use DateTimeZone;
use UpdateCheck\EolClient;
use UpdateCheck\HttpResponse;
use UpdateCheck\ImageCatalog;
use UpdateCheck\ImageReader;
use UpdateCheck\RuntimeVersions;
use UpdateCheck\ScanOutcome;
use UpdateCheck\ScanResult;
use UpdateCheck\Section;
use UpdateCheck\SeriesReader;
use UpdateCheck\Status;
use UpdateCheck\SupportLifecycleCheck;

/**
 * #6 実行の本体のパッチ版・#7 サポート期限と新しい系列・#8 compatibility_date。今日は 2026-10-15 に固定する。
 */
abstract class LifecycleTestCase extends UpdateCheckTestCase
{
    protected const DIGEST = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/updcheck-test-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/deploy/worker', 0777, true);
        file_put_contents($this->root.'/Dockerfile', 'FROM dunglas/frankenphp:1.12.7-php8.4.26-trixie@'.self::DIGEST." AS base\nCOPY --from=node:22.23.2-trixie-slim@".self::DIGEST." /a /b\n");
        file_put_contents($this->root.'/composer.lock', '{"packages":[{"name":"x/y","version":"v1.0.0"},{"name":"laravel/framework","version":"v13.34.0"}]}');
        file_put_contents($this->root.'/package-lock.json', '{"packages":{"node_modules/react":{"version":"19.3.0"}}}');
        file_put_contents($this->root.'/deploy/worker/wrangler.jsonc', "{\n  // c\n  \"compatibility_date\": \"2026-09-25\",\n}\n");
    }

    protected function tearDown(): void
    {
        foreach (['/Dockerfile', '/composer.lock', '/package-lock.json', '/deploy/worker/wrangler.jsonc'] as $file) {
            @unlink($this->root.$file);
        }

        rmdir($this->root.'/deploy/worker');
        rmdir($this->root.'/deploy');
        rmdir($this->root);

        parent::tearDown();
    }

    /**
     * @param  list<array{string, string, string|null, bool, string}>  $cycles  名前・公開日・期限・期限切れか・最新の版
     */
    protected function product(array $cycles): HttpResponse
    {
        return FakeHttpClient::json(['result' => ['releases' => array_map(static fn (array $c): array => [
            'name' => $c[0], 'releaseDate' => $c[1], 'eolFrom' => $c[2], 'isEol' => $c[3], 'latest' => ['name' => $c[4]],
        ], $cycles)]]);
    }

    /**
     * @param  array<string, HttpResponse>  $products  製品 → 応答
     */
    protected function eol(array $products): EolClient
    {
        $responses = [];

        foreach ($products as $product => $response) {
            $responses["GET https://endoflife.date/api/v1/products/{$product}/"] = $response;
        }

        return new EolClient(new FakeHttpClient($responses));
    }

    protected function lifecycle(EolClient $eol, ?ScanOutcome $scan = null, ?RuntimeVersions $runtime = null): Section
    {
        $specs = [];

        foreach (ImageCatalog::all() as $spec) {
            $specs[$spec->label] = $spec;
        }

        return (new SupportLifecycleCheck(
            new SeriesReader($this->root, new ImageReader($this->root), $specs),
            $runtime ?? new RuntimeVersions(['PostgreSQL' => '18.4'], []),
            $scan ?? new ScanOutcome([new ScanResult('app', [], 'debian', '13.1'), new ScanResult('db', [], 'alpine', '3.22.1')], []),
            $eol,
            new DateTimeImmutable('2026-10-15', new DateTimeZone('UTC')),
        ))->run();
    }

    /**
     * @return array<string, array{Status, string}>
     */
    protected function statuses(Section $section): array
    {
        $result = [];

        foreach ($section->rows as $row) {
            $result[$row->cells[0]] = [$row->status, $row->cells[3]];
        }

        return $result;
    }

    /**
     * @return array<string, HttpResponse>
     */
    protected function products(): array
    {
        return [
            'php' => $this->product([['8.5', '2025-11-20', '2029-12-31', false, '8.5.11'], ['8.4', '2024-11-21', '2028-12-31', false, '8.4.26']]),
            'nodejs' => $this->product([['22', '2024-04-24', '2027-04-30', false, '22.23.3']]),
            'laravel' => $this->product([['13', '2026-03-17', '2028-03-17', false, '13.36.0']]),
            'react' => $this->product([['19', '2024-12-05', null, false, '19.3.1']]),
            'postgresql' => $this->product([['19', '2026-09-30', '2031-11-12', false, '19.0'], ['18', '2025-09-25', '2030-11-14', false, '18.6']]),
            'debian' => $this->product([['13', '2025-08-09', '2030-06-30', false, '13.7']]),
            'alpine-linux' => $this->product([['3.24', '2026-06-09', '2028-06-01', false, '3.24.2'], ['3.22', '2025-05-30', '2027-05-01', false, '3.22.6']]),
        ];
    }
}
