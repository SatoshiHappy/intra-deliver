<?php

namespace Tests\Unit\UpdateCheck;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use UpdateCheck\BudgetExceededException;
use UpdateCheck\CommandResult;
use UpdateCheck\CommandRunner;
use UpdateCheck\HttpResponse;
use UpdateCheck\Registry;
use UpdateCheck\RegistryClient;
use UpdateCheck\Report;
use UpdateCheck\Status;
use UpdateCheck\StreamHttpClient;
use UpdateCheck\TimeBudget;
use UpdateCheck\UnconfirmedException;
use UpdateCheck\UpdateChecker;

/**
 * 想定外の失敗・予算切れでも、結果のレポートは必ず作れ(8 項目が出て)、成功した項目の結果が残る。
 */
final class RobustnessTest extends UpdateCheckTestCase
{
    private function report(CommandRunner $commands): Report
    {
        return (new UpdateChecker($commands, new FakeHttpClient([]), self::budget(), self::root(), '', new DateTimeImmutable('2026-10-15', new DateTimeZone('UTC'))))->check();
    }

    public function test_どのコマンドが想定外の例外でも_8_項目が出て_コマンドを使わない項目の結果は残る(): void
    {
        $this->expectErrorLog();

        $runner = new class implements CommandRunner
        {
            public function run(array $command, string $cwd, int $timeoutSeconds = 300, bool $cleanup = false): CommandResult
            {
                throw new RuntimeException('boom');
            }
        };

        $full = $this->report($runner)->full();

        foreach (range(1, 8) as $number) {
            $this->assertStringContainsString("### 項目 {$number} ", $full);
        }

        $this->assertStringContainsString('RuntimeException', $full);
        $this->assertStringContainsString('`deploy/worker/wrangler.jsonc`', $full);
        $this->assertMatchesRegularExpression('/確認できなかった [1-9]\d* 件/', $full);
    }

    public function test_予算切れでも_8_項目が出て_理由は時間の予算切れ(): void
    {
        // 偽のコマンドはすべて予算切れを投げるので、DockerRun の後始末(docker rm)の失敗も error_log に書かれる
        $this->expectErrorLog();

        $runner = new class implements CommandRunner
        {
            public function run(array $command, string $cwd, int $timeoutSeconds = 300, bool $cleanup = false): CommandResult
            {
                throw new BudgetExceededException;
            }
        };

        $full = $this->report($runner)->full();

        foreach (range(1, 8) as $number) {
            $this->assertStringContainsString("### 項目 {$number} ", $full);
        }

        $this->assertStringContainsString('`時間の予算切れ`', $full);
    }

    public function test_タグの一覧がページの上限に達したら一部の一覧を返さず確認できなかった(): void
    {
        $url = 'https://registry-1.docker.io/v2/x/y/tags/list?n=10000';
        $client = new RegistryClient(new FakeHttpClient([
            'GET https://auth.docker.io/token?service=registry.docker.io&scope=repository:x/y:pull' => FakeHttpClient::json(['token' => 't']),
            'GET '.$url => new HttpResponse(200, ['link' => '</v2/x/y/tags/list?n=10000>; rel="next"'], '{"tags":["1.0.0"]}'),
        ]));

        $this->expectException(UnconfirmedException::class);
        $this->expectExceptionMessage('タグが多すぎる');

        $client->tags(Registry::DockerHub, 'x/y');
    }

    public function test_タグの一覧に文字列でない要素があれば確認できなかった(): void
    {
        $client = new RegistryClient(new FakeHttpClient([
            'GET https://auth.docker.io/token?service=registry.docker.io&scope=repository:x/y:pull' => FakeHttpClient::json(['token' => 't']),
            'GET https://registry-1.docker.io/v2/x/y/tags/list?n=10000' => FakeHttpClient::json(['tags' => ['1.0.0', 5]]),
        ]));

        $this->expectException(UnconfirmedException::class);

        $client->tags(Registry::DockerHub, 'x/y');
    }

    public function test_http_は予算が切れていれば要求を始めない(): void
    {
        $this->expectException(BudgetExceededException::class);

        (new StreamHttpClient(TimeBudget::starting(-1)))->request('GET', 'http://127.0.0.1:1/');
    }

    public function test_status_の区分は_4_つ(): void
    {
        $this->assertSame(['要対応', '更新あり', '表示だけ', '土台で未修正'], array_map(static fn (Status $s): string => $s->value, Status::cases()));
    }

    public function test_docker_の項目が予算切れでも_7_の_http_だけの行は残る_docker_より前に_endoflife_を照会する(): void
    {
        // 偽のコマンドはすべて予算切れを投げるので、DockerRun の後始末(docker rm)の失敗も error_log に書かれる
        $this->expectErrorLog();
        $log = self::callLog();
        $runner = new class($log) implements CommandRunner
        {
            /** @param  \ArrayObject<int, string>  $log */
            public function __construct(private \ArrayObject $log) {}

            public function run(array $command, string $cwd, int $timeoutSeconds = 300, bool $cleanup = false): CommandResult
            {
                $this->log->append('cmd:'.$command[0]);

                throw new BudgetExceededException;
            }
        };
        $cycle = static fn (string $name, string $latest): HttpResponse => FakeHttpClient::json(['result' => ['releases' => [
            ['name' => $name, 'releaseDate' => '2024-01-01', 'eolFrom' => '2030-01-01', 'isEol' => false, 'latest' => ['name' => $latest]],
        ]]]);
        $series = ['php' => '8.4', 'nodejs' => '22', 'laravel' => '13', 'react' => '19'];
        $responses = [];

        foreach ($series as $product => $name) {
            $responses["GET https://endoflife.date/api/v1/products/{$product}/"] = $cycle($name, "{$name}.9");
        }

        $report = (new UpdateChecker($runner, new FakeHttpClient($responses, $log), self::budget(), self::root(), '', new DateTimeImmutable('2026-10-15', new DateTimeZone('UTC'))))->check();
        $full = $report->full();

        foreach (['PHP', 'Node.js', 'Laravel', 'React'] as $label) {
            $this->assertMatchesRegularExpression("/\\| (表示だけ|更新あり|要対応) \\| `{$label}` \\|/u", $full);
        }

        $entries = $log->getArrayCopy();
        $firstDocker = array_search('cmd:docker', $entries, true);
        $lastEol = -1;

        foreach ($entries as $index => $entry) {
            if (str_contains($entry, 'endoflife.date')) {
                $lastEol = $index;
            }
        }

        $this->assertNotFalse($firstDocker);
        $this->assertGreaterThanOrEqual(0, $lastEol);
        $this->assertLessThan($firstDocker, $lastEol);
        $this->assertSame(1, substr_count($full, '### 項目 7 '));
    }

    /**
     * コマンドと HTTP の要求の記録(順番を比べる)。
     *
     * @return \ArrayObject<int, string>
     */
    private static function callLog(): \ArrayObject
    {
        return new \ArrayObject;
    }
}
