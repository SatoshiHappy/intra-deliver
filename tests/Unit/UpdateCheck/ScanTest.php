<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\CommandResult;
use UpdateCheck\ImageScanner;
use UpdateCheck\TrivyImage;
use UpdateCheck\UnconfirmedException;

/**
 * #5: Trivy の JSON を読んで区分する(修正版があれば要対応、無ければ修正版なし)。構築や検査が確認できなければ、その部分だけ確認できなかった。
 */
final class ScanTest extends UpdateCheckTestCase
{
    /**
     * @return list<string>
     */
    private function trivyCommand(string $image): array
    {
        return [
            'docker', 'run', '--rm',
            '--volume', '/var/run/docker.sock:/var/run/docker.sock',
            '--volume', ImageScanner::CACHE_VOLUME.':/cache',
            TrivyImage::REFERENCE,
            'image', '--cache-dir', '/cache', '--scanners', 'vuln', '--severity', 'HIGH,CRITICAL', '--format', 'json', '--quiet', $image,
        ];
    }

    public function test_構築の失敗はそのイメージだけ確認できなかったにして残りを続ける(): void
    {
        $runner = new FakeCommandRunner([
            'docker build --quiet --target base --tag updcheck-app:scan --file Dockerfile .' => new CommandResult(1, '', "step\nERROR: failed to solve\n"),
            'docker build --quiet --tag updcheck-db:scan --file docker/db/Dockerfile docker' => new CommandResult(0, 'sha256:abc', ''),
            implode(' ', $this->trivyCommand('updcheck-db:scan')) => new CommandResult(0, '{"SchemaVersion":2}', ''),
            implode(' ', $this->trivyCommand('cloudflare/cloudflared:1@sha256:x')) => new CommandResult(1, 'not json', 'FATAL db error'),
        ]);

        $outcome = (new ImageScanner($runner, '/x', self::budget(), static fn (): string => 'cloudflare/cloudflared:1@sha256:x'))->scan();

        $this->assertSame([false, true, false], [$outcome->confirmedFor('app'), $outcome->confirmedFor('db'), $outcome->confirmedFor('tunnel')]);
        $this->assertSame(['db'], array_map(static fn ($r): string => $r->image, $outcome->results));
        $this->assertSame(['app', 'tunnel'], array_map(static fn ($f): string => $f->subject, $outcome->failures));
        $this->assertStringContainsString('ERROR: failed to solve', $outcome->failures[0]->reason);
        $this->assertStringContainsString('FATAL db error', $outcome->failures[1]->reason);
        $this->assertSame([], $outcome->newScans);
    }

    public function test_新しい土台は_build_の引数で差し替えてもう_1_回検査する_tunnel_は直接検査する(): void
    {
        $empty = new CommandResult(0, '{"SchemaVersion":2}', '');
        $runner = new FakeCommandRunner([
            'docker build --quiet --target base --tag updcheck-app:scan --file Dockerfile .' => new CommandResult(0, 'sha256:a', ''),
            implode(' ', $this->trivyCommand('updcheck-app:scan')) => $empty,
            'docker build --quiet --target base --build-arg FRANKENPHP_IMAGE=dunglas/frankenphp:2@sha256:n --tag updcheck-app-new:scan --file Dockerfile .' => new CommandResult(0, 'sha256:b', ''),
            implode(' ', $this->trivyCommand('updcheck-app-new:scan')) => $empty,
            'docker build --quiet --tag updcheck-db:scan --file docker/db/Dockerfile docker' => new CommandResult(0, 'sha256:c', ''),
            implode(' ', $this->trivyCommand('updcheck-db:scan')) => $empty,
            implode(' ', $this->trivyCommand('cloudflare/cloudflared:1@sha256:x')) => $empty,
            implode(' ', $this->trivyCommand('cloudflare/cloudflared:2@sha256:y')) => $empty,
        ]);

        $outcome = (new ImageScanner(
            $runner,
            '/x',
            self::budget(),
            static fn (): string => 'cloudflare/cloudflared:1@sha256:x',
            ['app' => 'dunglas/frankenphp:2@sha256:n', 'tunnel' => 'cloudflare/cloudflared:2@sha256:y'],
        ))->scan();

        $this->assertSame([true, true, true], [$outcome->confirmedFor('app'), $outcome->confirmedFor('db'), $outcome->confirmedFor('tunnel')]);
        $this->assertSame([], $outcome->newFailures);
        $this->assertSame(['app', 'tunnel'], array_keys($outcome->newScans));
        $this->assertCount(8, $runner->calls);
    }

    public function test_新しい土台の構築か検査の失敗は固定したイメージの結果に影響しない(): void
    {
        $empty = new CommandResult(0, '{"SchemaVersion":2}', '');
        $runner = new FakeCommandRunner([
            'docker build --quiet --tag updcheck-db:scan --file docker/db/Dockerfile docker' => new CommandResult(0, 'sha256:c', ''),
            'docker build --quiet --target base --tag updcheck-app:scan --file Dockerfile .' => new CommandResult(0, 'sha256:a', ''),
            implode(' ', $this->trivyCommand('updcheck-app:scan')) => $empty,
            implode(' ', $this->trivyCommand('updcheck-db:scan')) => $empty,
            implode(' ', $this->trivyCommand('t')) => $empty,
            'docker build --quiet --target base --build-arg FRANKENPHP_IMAGE=r --tag updcheck-app-new:scan --file Dockerfile .' => new CommandResult(1, '', 'new base failed'),
        ]);

        $outcome = (new ImageScanner($runner, '/x', self::budget(), static fn (): string => 't', ['app' => 'r']))->scan();

        $this->assertSame([true, true, true], [$outcome->confirmedFor('app'), $outcome->confirmedFor('db'), $outcome->confirmedFor('tunnel')]);
        $this->assertSame(['app(新しい土台)'], array_map(static fn ($f): string => $f->subject, array_values($outcome->newFailures)));
        $this->assertStringContainsString('new base failed', $outcome->newFailures['app']->reason);
    }

    public function test_trivy_のイメージは_digest_で固定されている(): void
    {
        $this->assertMatchesRegularExpression('#^ghcr\.io/aquasecurity/trivy:\d+\.\d+\.\d+@sha256:[0-9a-f]{64}$#D', TrivyImage::REFERENCE);
    }

    public function test_4_の照会が失敗した画像も_新しい土台の検査が確認できなかった扱い(): void
    {
        $runner = new FakeCommandRunner([
            'docker build --quiet --tag updcheck-db:scan --file docker/db/Dockerfile docker' => new CommandResult(0, 'sha256:c', ''),
            implode(' ', $this->trivyCommand('updcheck-db:scan')) => new CommandResult(0, '{"SchemaVersion":2}', ''),
        ]);

        $outcome = (new ImageScanner($runner, '/x', self::budget(), static fn (): string => 't', [], ['db']))->scan();

        $this->assertSame(['db'], $outcome->unverified());
    }

    public function test_新しい土台の_build_の失敗は新しい土台の検査が確認できなかったに分類される(): void
    {
        $empty = new CommandResult(0, '{"SchemaVersion":2}', '');
        $runner = new FakeCommandRunner([
            'docker build --quiet --target base --tag updcheck-app:scan --file Dockerfile .' => new CommandResult(0, 'a', ''),
            implode(' ', $this->trivyCommand('updcheck-app:scan')) => $empty,
            'docker build --quiet --target base --build-arg FRANKENPHP_IMAGE=r --tag updcheck-app-new:scan --file Dockerfile .' => new CommandResult(1, '', 'new base failed'),
            'docker build --quiet --tag updcheck-db:scan --file docker/db/Dockerfile docker' => new CommandResult(0, 'c', ''),
            implode(' ', $this->trivyCommand('updcheck-db:scan')) => $empty,
            implode(' ', $this->trivyCommand('t')) => $empty,
        ]);

        $outcome = (new ImageScanner($runner, '/x', self::budget(), static fn (): string => 't', ['app' => 'r']))->scan();

        $this->assertSame(['app'], $outcome->unverified());
    }

    public function test_想定外の例外でも_5_だけ確認できなかったになり_ほかの結果は残る(): void
    {
        $this->expectErrorLog();

        $runner = new FakeCommandRunner([
            'docker build --quiet --target base --tag updcheck-app:scan --file Dockerfile .' => new \RuntimeException('boom'),
            'docker build --quiet --tag updcheck-db:scan --file docker/db/Dockerfile docker' => new CommandResult(0, 'c', ''),
            implode(' ', $this->trivyCommand('updcheck-db:scan')) => new CommandResult(0, '{"SchemaVersion":2}', ''),
            implode(' ', $this->trivyCommand('t')) => new \TypeError('bad'),
        ]);

        $outcome = (new ImageScanner($runner, '/x', self::budget(), static fn (): string => 't'))->scan();

        $this->assertSame(['db'], array_map(static fn ($r): string => $r->image, $outcome->results));
        $this->assertSame(['app', 'tunnel'], array_map(static fn ($f): string => $f->subject, $outcome->failures));
        $this->assertStringContainsString('RuntimeException', $outcome->failures[0]->reason);
        $this->assertStringContainsString('TypeError', $outcome->failures[1]->reason);
    }

    public function test_cleanup_は消せなかったコマンドの理由を返す(): void
    {
        $remove = 'docker image rm --force '.implode(' ', [ImageScanner::APP_IMAGE, ImageScanner::DB_IMAGE, ImageScanner::APP_NEW_IMAGE, ImageScanner::DB_NEW_IMAGE]);
        $volume = 'docker volume rm --force '.ImageScanner::CACHE_VOLUME;

        $failed = new FakeCommandRunner([$remove => new CommandResult(1, '', 'x'), $volume => new CommandResult(0, '', '')]);
        $lines = (new ImageScanner($failed, '/x', self::budget(), static fn (): string => ''))->cleanup();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('終了コード 1', $lines[0]);
        $this->assertStringContainsString('x', $lines[0]);

        $ok = new FakeCommandRunner([$remove => new CommandResult(0, '', ''), $volume => new CommandResult(0, '', '')]);

        $this->assertSame([], (new ImageScanner($ok, '/x', self::budget(), static fn (): string => ''))->cleanup());
    }

    public function test_cleanup_は時間切れの例外で落ちず_理由を返して残りのコマンドも流す(): void
    {
        $remove = 'docker image rm --force '.implode(' ', [ImageScanner::APP_IMAGE, ImageScanner::DB_IMAGE, ImageScanner::APP_NEW_IMAGE, ImageScanner::DB_NEW_IMAGE]);
        $volume = 'docker volume rm --force '.ImageScanner::CACHE_VOLUME;
        $runner = new FakeCommandRunner([$remove => new UnconfirmedException('時間の枠が切れた'), $volume => new CommandResult(0, '', '')]);

        $lines = (new ImageScanner($runner, '/x', self::budget(), static fn (): string => ''))->cleanup();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('時間の枠が切れた', $lines[0]);
        $this->assertCount(2, $runner->calls);
    }
}
