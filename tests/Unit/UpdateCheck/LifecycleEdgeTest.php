<?php

namespace Tests\Unit\UpdateCheck;

use DateTimeImmutable;
use DateTimeZone;
use UpdateCheck\Dates;
use UpdateCheck\EolClient;
use UpdateCheck\HttpClient;
use UpdateCheck\HttpResponse;
use UpdateCheck\ImageCatalog;
use UpdateCheck\ImageReader;
use UpdateCheck\RuntimeVersionCheck;
use UpdateCheck\RuntimeVersions;
use UpdateCheck\SeriesReader;
use UpdateCheck\Status;
use UpdateCheck\SupportLifecycleCheck;
use UpdateCheck\Unconfirmed;
use UpdateCheck\UnconfirmedException;
use UpdateCheck\UpdateChecker;
use UpdateCheck\WorkerDateCheck;

/**
 * #6 実行の本体のパッチ版・#7 サポート期限と新しい系列・#8 compatibility_date。今日は 2026-10-15 に固定する。
 */
final class LifecycleEdgeTest extends LifecycleTestCase
{
    public function test_eolfrom_が読めない日付ならその製品だけ確認できなかった(): void
    {
        $products = $this->products();
        $products['nodejs'] = $this->product([['22', '2024-04-24', 'invalid', false, '22.23.3']]);

        $section = $this->lifecycle($this->eol($products));

        $this->assertSame(['Node.js'], array_map(static fn (Unconfirmed $u): string => $u->subject, $section->unconfirmed));
        $this->assertStringContainsString('eolFrom', $section->unconfirmed[0]->reason);
        $this->assertCount(6, $section->rows);
    }

    public function test_日付でない文字列_tomorrow_や_2026_は日付に読まない(): void
    {
        foreach (['tomorrow', '2026', '2026-13-45', '2026-10-15 10:00'] as $text) {
            $products = $this->products();
            $products['laravel'] = $this->product([['13', '2026-03-17', $text, false, '13.36.0']]);

            $this->assertSame(['Laravel'], array_map(static fn (Unconfirmed $u): string => $u->subject, $this->lifecycle($this->eol($products))->unconfirmed), $text);
        }
    }

    public function test_eolfrom_や_is_eol_の鍵が無ければ確認できなかった_eolfrom_が_null_なら期限なし(): void
    {
        $products = $this->products();
        $products['laravel'] = FakeHttpClient::json(['result' => ['releases' => [['name' => '13', 'releaseDate' => '2026-03-17', 'isEol' => false, 'latest' => ['name' => '13.36.0']]]]]);
        $products['react'] = FakeHttpClient::json(['result' => ['releases' => [['name' => '19', 'releaseDate' => '2024-12-05', 'eolFrom' => null, 'latest' => ['name' => '19.3.1']]]]]);

        $section = $this->lifecycle($this->eol($products));

        $this->assertSame(['Laravel', 'React'], array_map(static fn (Unconfirmed $u): string => $u->subject, $section->unconfirmed));
        $this->assertStringContainsString('eolFrom が無い', $section->unconfirmed[0]->reason);
        $this->assertStringContainsString('isEol', $section->unconfirmed[1]->reason);
    }

    public function test_使わない古い系列の不備で製品ごと確認できなくならない(): void
    {
        $products = $this->products();
        $products['php'] = $this->product([['8.5', '2025-11-20', '2029-12-31', false, '8.5.11'], ['8.4', '2024-11-21', '2028-12-31', false, '8.4.26'], ['5.6', 'broken', 'broken', false, '5.6.40']]);

        $this->assertSame([], $this->lifecycle($this->eol($products))->unconfirmed);
    }

    public function test_日数は_php_の既定のタイムゾーンに依らず_utc_の暦日で数える(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $products = $this->products();
            $products['nodejs'] = $this->product([['22', '2024-04-24', '2027-01-13', false, '22.23.3']]);
            $section = $this->lifecycle($this->eol($products));
            $worker = (new WorkerDateCheck($this->root, new DateTimeImmutable('2026-10-15 00:30', new DateTimeZone('UTC'))))->run();
        } finally {
            date_default_timezone_set($default);
        }

        $this->assertSame([Status::Action, 'あと 90 日'], $this->statuses($section)['Node.js']);
        $this->assertSame('20', $worker->rows[0]->cells[2]);
    }

    public function test_今日は与えられた時刻の_utc_の暦日(): void
    {
        $jst = new DateTimeImmutable('2026-10-15 05:00', new DateTimeZone('Asia/Tokyo'));

        $this->assertSame('2026-10-14', Dates::today($jst)->format('Y-m-d'));
    }

    public function test_系列の読み取りは_imagecatalog_の系列の正規表現を使う(): void
    {
        $specs = [];

        foreach (ImageCatalog::all() as $spec) {
            $specs[$spec->label] = $spec;
        }

        $series = new SeriesReader($this->root, new ImageReader($this->root), $specs);

        $this->assertSame(['8.4', '22'], [$series->php(), $series->node()]);
        $this->assertNotNull($specs['frankenphp']->seriesPattern);
        $this->assertNotNull($specs['node']->seriesPattern);
    }

    public function test_prefetch_は照会を先に済ませ_失敗は覚えて使うときに投げ_再照会しない(): void
    {
        $log = new \ArrayObject;
        $client = new EolClient(new FakeHttpClient([
            'GET https://endoflife.date/api/v1/products/php/' => $this->products()['php'],
        ], $log));

        $client->prefetch(['php', 'nodejs']);

        $this->assertCount(2, $log);
        $this->assertSame('8.4.26', $client->cycle('php', '8.4')->latest());

        try {
            $client->cycles('nodejs');
            $this->fail('失敗は使うときに投げられる');
        } catch (UnconfirmedException $exception) {
            $this->assertStringContainsString('HTTP 404', $exception->getMessage());
        }

        $this->assertCount(2, $log);
    }

    public function test_先取りする製品の一覧は_6_と_7_の各項目の一覧から集める(): void
    {
        $this->assertEqualsCanonicalizing(
            ['php', 'nodejs', 'laravel', 'react', 'postgresql', 'debian', 'alpine-linux'],
            UpdateChecker::eolProducts(),
        );
        $this->assertSame([], array_diff(RuntimeVersionCheck::products(), UpdateChecker::eolProducts()));
        $this->assertSame([], array_diff(SupportLifecycleCheck::products(), UpdateChecker::eolProducts()));
    }

    public function test_先取りした後の_6_と_7_は新しい照会をしない(): void
    {
        $log = new \ArrayObject;
        $responses = [];

        foreach ($this->products() as $product => $response) {
            $responses["GET https://endoflife.date/api/v1/products/{$product}/"] = $response;
        }

        $client = new EolClient(new FakeHttpClient($responses, $log));
        $client->prefetch(UpdateChecker::eolProducts());
        $requested = count($log);

        $section = $this->lifecycle($client);
        (new RuntimeVersionCheck(new RuntimeVersions(['PHP' => '8.4.26', 'PostgreSQL' => '18.4'], []), $client))->run();

        $this->assertSame([], $section->unconfirmed);
        $this->assertSame(count(UpdateChecker::eolProducts()), $requested);
        $this->assertCount($requested, $log);
    }

    public function test_想定外の例外の照会の失敗は_1_回だけログに残し_行ごとには出さない(): void
    {
        $this->expectErrorLog();
        $http = new class implements HttpClient
        {
            public function request(string $method, string $url, array $headers = []): HttpResponse
            {
                throw new \RuntimeException('boom');
            }
        };
        $client = new EolClient($http);
        $client->prefetch(['php']);

        foreach ([1, 2, 3] as $ignored) {
            try {
                $client->cycles('php');
                $this->fail('例外になるはず');
            } catch (UnconfirmedException $exception) {
                $this->assertStringContainsString('RuntimeException', $exception->getMessage());
            }
        }

        $this->assertSame(1, substr_count($this->errorLog(), '想定外の失敗'));
    }
}
