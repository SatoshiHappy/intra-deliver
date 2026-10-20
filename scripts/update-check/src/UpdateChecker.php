<?php

namespace UpdateCheck;

use DateTimeImmutable;

/**
 * #1〜#8 を調べて、結果を番号の順にまとめる(流す順 = docs の 設計/更新チェック.md 2 節)。項目はそれぞれ独立に判定し、1 つが失敗しても残りを続ける(設計/更新チェック.md 5 節)。
 * #6・#7 は #5(イメージの構築と検査)の結果を使うので、#5 を先に流す。
 */
final readonly class UpdateChecker
{
    public function __construct(
        private CommandRunner $commands,
        private HttpClient $http,
        private TimeBudget $budget,
        private string $root,
        private string $runUrl,
        private DateTimeImmutable $now,
    ) {}

    public function check(): Report
    {
        $registry = new RegistryClient($this->http);
        $images = new ImageReader($this->root);
        $specs = ImageCatalog::all();
        $named = [];

        foreach ($specs as $spec) {
            $named[$spec->label] = $spec;
        }

        // docker を使わない処理を先に流す(時間の予算を docker の項目が使い切っても、先に流した結果は残る)
        $sections = [
            (new ComposerOutdatedCheck($this->commands, $this->root))->run(),
            (new NpmOutdatedCheck($this->commands, $this->root, sys_get_temp_dir()))->run(),
            (new AuditCheck($this->commands, $this->root))->run(),
        ];

        // #6・#7 の endoflife.date の照会は docker の検査より前に済ませる(予算を docker の項目が使い切っても、HTTP の照会は残る)
        $eol = new EolClient($this->http);
        $eol->prefetch(self::eolProducts());

        $updates = (new ImageUpdateFinder($registry, $images))->findAll($specs);
        $sections[] = (new ImageUpdateCheck($updates))->run();
        $sections[] = (new WorkerDateCheck($this->root, $this->now))->run();

        $scan = (new ImageScanner(
            $this->commands,
            $this->root,
            $this->budget,
            fn (): string => $this->tunnelReference($images, $named['cloudflared']),
            $this->newBases($updates),
            $this->unknownNewBases($updates),
        ))->scan();
        $runtime = (new RuntimeVersionReader($this->commands, $this->root, $this->budget))->read($scan);

        $sections[] = (new ImageScanCheck($scan))->run();
        $sections[] = (new RuntimeVersionCheck($runtime, $eol))->run();
        $sections[] = (new SupportLifecycleCheck(new SeriesReader($this->root, $images, $named), $runtime, $scan, $eol, $this->now))->run();

        usort($sections, static fn (Section $a, Section $b): int => (int) ltrim($a->id, '#') <=> (int) ltrim($b->id, '#'));

        return new Report($sections, $this->runUrl);
    }

    /**
     * 先取りする endoflife.date の製品。#6・#7 の各項目が持つ製品の一覧から集める(別に書かない。足し忘れると、その行の照会が docker の後に回る)。
     *
     * @return list<string>
     */
    public static function eolProducts(): array
    {
        return array_values(array_unique([...RuntimeVersionCheck::products(), ...SupportLifecycleCheck::products()]));
    }

    /**
     * 新しい土台がある実行用のイメージ(app = frankenphp、db = pgroonga、tunnel = cloudflared)→ 新しい土台の参照。
     *
     * @return array<string, string>
     */
    private function newBases(ImageUpdateResults $updates): array
    {
        $bases = [];

        foreach (['app' => 'frankenphp', 'db' => 'pgroonga', 'tunnel' => 'cloudflared'] as $name => $label) {
            $base = $updates->newBase($label);

            if ($base !== null) {
                $bases[$name] = $base;
            }
        }

        return $bases;
    }

    /**
     * 新しい土台の有無を #4 で調べられなかった実行用のイメージの名前(新しい土台の検査が確認できなかった扱いにする)。
     *
     * @return list<string>
     */
    private function unknownNewBases(ImageUpdateResults $updates): array
    {
        $names = [];

        foreach (['app' => 'frankenphp', 'db' => 'pgroonga', 'tunnel' => 'cloudflared'] as $name => $label) {
            if (isset($updates->failures[$label])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * tunnel のイメージは、固定した参照(名前:タグ@digest)をそのまま Trivy に渡す。
     *
     * @throws UnconfirmedException
     */
    private function tunnelReference(ImageReader $images, ImageSpec $spec): string
    {
        $ref = $images->read($spec);

        return $spec->written.':'.$ref->tag.($ref->digest === null ? '' : '@'.$ref->digest);
    }
}
