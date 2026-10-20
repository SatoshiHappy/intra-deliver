<?php

namespace UpdateCheck;

/**
 * #2 npm の直接依存の新しい版(Playwright・wrangler を含む)。一時のディレクトリに lock を写して `npm ci` の後に `npm outdated` を流す
 * (作業木の node_modules を触らない)。`npm ci` が失敗したら #2 だけ確認できなかった(`npm outdated` は node_modules が無いと黙って 0 件になるので流さない)。
 * `npm ci` は出力の形が無いので、終了コードで成否を見る(設計/更新チェック.md 5 節)。
 */
final readonly class NpmOutdatedCheck implements Check
{
    public function __construct(
        private CommandRunner $commands,
        private string $root,
        private string $workspaceParent,
    ) {}

    public function run(): Section
    {
        $builder = new SectionBuilder('#2', 'npm の直接依存の新しい版', ['パッケージ', '現在', '許容範囲の最新', '最新']);
        $builder->attempt('npm outdated', $this->rows(...));

        return $builder->build();
    }

    /**
     * @return list<Row>
     */
    private function rows(): array
    {
        $workspace = NpmWorkspace::create($this->root, $this->workspaceParent);

        try {
            $install = $this->commands->run(['npm', 'ci', '--ignore-scripts'], $workspace->path, 600);

            if ($install->exitCode !== 0) {
                throw new UnconfirmedException('npm ci に失敗した: '.$install->tail());
            }

            $result = $this->commands->run(['npm', 'outdated', '--json'], $workspace->path, 120);
        } finally {
            $workspace->remove();
        }

        $packages = Json::parse($result->stdout, 'npm outdated');
        NpmErrors::throwIfError($packages, 'npm outdated');

        return $this->packageRows($packages);
    }

    /**
     * @param  array<array-key, mixed>  $packages
     * @return list<Row>
     *
     * @throws UnconfirmedException
     */
    private function packageRows(array $packages): array
    {
        $rows = [];

        foreach ($packages as $name => $entries) {
            foreach ($this->entries($entries, (string) $name) as $entry) {
                $row = $this->row((string) $name, $entry);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * 同じ名前が複数の場所にあるときは配列になる。表でない要素は確認できなかった。
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws UnconfirmedException
     */
    private function entries(mixed $entries, string $name): array
    {
        if (! is_array($entries)) {
            throw new UnconfirmedException("npm outdated の {$name} が表でない");
        }

        return array_is_list($entries) ? Json::tables($entries, "npm outdated の {$name}") : [$entries];
    }

    /**
     * 形が正しく意図して除く行(入っていない他の OS 用の任意の依存で、許容範囲の版が最新と同じもの)は null。形が不正な行は確認できなかった。
     * 要る鍵: wanted・latest(文字列)。current は任意(入っていないと無い)。
     *
     * @param  array<array-key, mixed>  $entry
     *
     * @throws UnconfirmedException
     */
    private function row(string $name, array $entry): ?Row
    {
        $part = "npm outdated の {$name}";
        $wanted = Json::string($entry, 'wanted', $part);
        $latest = Json::string($entry, 'latest', $part);
        $current = Json::nullableString($entry, 'current', $part) ?? '';

        if ($current === '' && $wanted === $latest) {
            return null;
        }

        return new Row(Status::Update, [$name, $current === '' ? '(入っていない)' : $current, $wanted, $latest]);
    }
}
