<?php

namespace UpdateCheck;

use Closure;
use Throwable;

/**
 * 実行に使うイメージを用意して Trivy で検査する(設計/更新チェック.md 1 節の #5)。docker を使う。
 * app = Dockerfile の base の段(土台 + PHP の拡張)、db = docker/db/Dockerfile、tunnel = 固定した cloudflared(Trivy が取る)。
 * 脆弱性だけを検査する(重大度 HIGH・CRITICAL。パッケージの種類で絞らない)。
 * 新しい土台があるイメージは、土台を差し替えてもう 1 回検査する(app・db は build の引数で土台を差し替える。tunnel はそのイメージを直接検査する)。
 */
final class ImageScanner
{
    public const APP_IMAGE = 'updcheck-app:scan';

    public const DB_IMAGE = 'updcheck-db:scan';

    public const APP_NEW_IMAGE = 'updcheck-app-new:scan';

    public const DB_NEW_IMAGE = 'updcheck-db-new:scan';

    public const CACHE_VOLUME = 'updcheck-trivy-cache';

    /** 名前 → [Dockerfile, build の文脈, 段(無ければ null), 土台を差し替える ARG の名前, 固定した build のイメージ名, 新しい土台の build のイメージ名] */
    private const BUILDS = [
        'app' => ['Dockerfile', '.', 'base', 'FRANKENPHP_IMAGE', self::APP_IMAGE, self::APP_NEW_IMAGE],
        'db' => ['docker/db/Dockerfile', 'docker', null, 'PGROONGA_IMAGE', self::DB_IMAGE, self::DB_NEW_IMAGE],
    ];

    private DockerRun $docker;

    /**
     * @param  Closure(): string  $tunnelReference  固定した cloudflared の参照(名前:タグ@digest)
     * @param  array<string, string>  $newBases  名前(app・db・tunnel)→ 新しい土台の参照(名前:タグ@digest)。無いものは 1 回だけ検査する
     * @param  list<string>  $unknownNewBases  新しい土台の有無を #4 で調べられなかった名前(新しい土台の検査が確認できなかった扱い)
     */
    public function __construct(
        private CommandRunner $commands,
        private string $root,
        TimeBudget $budget,
        private Closure $tunnelReference,
        private array $newBases = [],
        private array $unknownNewBases = [],
    ) {
        $this->docker = new DockerRun($commands, $root, $budget);
    }

    public function scan(): ScanOutcome
    {
        $results = [];
        $failures = [];
        $newScans = [];
        $newFailures = [];

        foreach (['app', 'db', 'tunnel'] as $label) {
            try {
                $results[] = $this->scanOne($label, null);
            } catch (Throwable $exception) {
                $failures[] = Unconfirmeds::from($label, $exception);
            }

            if (isset($this->newBases[$label])) {
                try {
                    $newScans[$label] = $this->scanOne($label, $this->newBases[$label]);
                } catch (Throwable $exception) {
                    $newFailures[$label] = Unconfirmeds::from("{$label}(新しい土台)", $exception);
                }
            }
        }

        return new ScanOutcome($results, $failures, $newScans, $newFailures, $this->unknownNewBases);
    }

    /**
     * 作った一時のイメージと Trivy のキャッシュを消す(ローカルの実行用)。消せなかったコマンドの理由を返す(呼び元が標準エラーに出す)。
     *
     * @return list<string>
     */
    public function cleanup(): array
    {
        $failures = [];
        $removals = [
            '一時のイメージを消せない' => ['docker', 'image', 'rm', '--force', self::APP_IMAGE, self::DB_IMAGE, self::APP_NEW_IMAGE, self::DB_NEW_IMAGE],
            'Trivy のキャッシュを消せない' => ['docker', 'volume', 'rm', '--force', self::CACHE_VOLUME],
        ];

        foreach ($removals as $what => $command) {
            try {
                $result = $this->commands->run($command, $this->root, 120, true);
            } catch (UnconfirmedException $exception) {
                $failures[] = "{$what}: ".$exception->getMessage();

                continue;
            }

            if ($result->exitCode !== 0) {
                $failures[] = "{$what}(終了コード {$result->exitCode}): ".$result->tail();
            }
        }

        return $failures;
    }

    /**
     * $newBase が null なら固定したイメージ、あれば新しい土台に差し替えたイメージを用意して検査する。
     *
     * @throws UnconfirmedException
     */
    private function scanOne(string $label, ?string $newBase): ScanResult
    {
        if (! isset(self::BUILDS[$label])) {
            return $this->trivy($label, $newBase ?? ($this->tunnelReference)());
        }

        $image = $newBase === null ? self::BUILDS[$label][4] : self::BUILDS[$label][5];
        $this->build($label, $this->buildCommand($label, $image, $newBase));

        return $this->trivy($label, $image);
    }

    /**
     * @return list<string>
     */
    private function buildCommand(string $label, string $image, ?string $newBase): array
    {
        [$file, $context, $target, $argument] = self::BUILDS[$label];

        return [
            'docker', 'build', '--quiet',
            ...($target === null ? [] : ['--target', $target]),
            ...($newBase === null ? [] : ['--build-arg', $argument.'='.$newBase]),
            '--tag', $image, '--file', $file, $context,
        ];
    }

    /**
     * 構築の成否は終了コードで見る(出力の形が無い)。
     *
     * @param  list<string>  $command
     *
     * @throws UnconfirmedException
     */
    private function build(string $label, array $command): void
    {
        $result = $this->commands->run($command, $this->root, 900);

        if ($result->exitCode !== 0) {
            throw new UnconfirmedException("{$label} のイメージを作れない: ".$result->tail());
        }
    }

    /**
     * @throws UnconfirmedException
     */
    private function trivy(string $label, string $image): ScanResult
    {
        $result = $this->docker->run([
            '--volume', '/var/run/docker.sock:/var/run/docker.sock',
            '--volume', self::CACHE_VOLUME.':/cache',
            TrivyImage::REFERENCE,
            'image', '--cache-dir', '/cache', '--scanners', 'vuln', '--severity', 'HIGH,CRITICAL', '--format', 'json', '--quiet', $image,
        ], 600);

        try {
            return TrivyReport::parse($result->stdout, $label);
        } catch (UnconfirmedException $exception) {
            throw new UnconfirmedException($exception->getMessage().': '.$result->tail());
        }
    }
}
