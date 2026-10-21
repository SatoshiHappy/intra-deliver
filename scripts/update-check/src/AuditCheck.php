<?php

namespace UpdateCheck;

/**
 * #3 composer・npm の脆弱性(推移的依存を含む)と、composer の放棄されたパッケージ。見つかったものはすべて要対応。
 */
final readonly class AuditCheck implements Check
{
    public function __construct(
        private CommandRunner $commands,
        private string $root,
    ) {}

    public function run(): Section
    {
        $builder = new SectionBuilder('#3', '脆弱性と放棄されたパッケージ', ['種類', 'パッケージ', '内容', '重大度', '修正']);
        $builder->attempt('composer audit', $this->composer(...));
        $builder->attempt('npm audit', $this->npm(...));

        return $builder->build();
    }

    /**
     * @return list<Row>
     */
    private function composer(): array
    {
        $result = $this->commands->run(['composer', 'audit', '--locked', '--format=json', '--no-interaction'], $this->root, 120);

        try {
            $output = Json::parse($result->stdout, 'composer audit');

            return [
                ...$this->advisoryRows(Json::table($output, 'advisories', 'composer audit')),
                ...$this->abandonedRows(Json::table($output, 'abandoned', 'composer audit')),
            ];
        } catch (UnconfirmedException $exception) {
            throw self::withStderr($exception, $result);
        }
    }

    /**
     * @param  array<array-key, mixed>  $advisories  パッケージ名 → 脆弱性の並び
     * @return list<Row>
     */
    private function advisoryRows(array $advisories): array
    {
        $rows = [];

        foreach ($advisories as $package => $items) {
            if (! is_array($items)) {
                throw new UnconfirmedException("composer audit の {$package} が表でない");
            }

            foreach (Json::tables($items, "composer audit の {$package}") as $item) {
                $rows[] = new Row(Status::Action, [
                    'composer の脆弱性', (string) $package, Json::string($item, 'title', "composer audit の {$package}"), Json::optionalString($item, 'severity'), Json::optionalString($item, 'affectedVersions'),
                ]);
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $abandoned  パッケージ名 → 代わりのパッケージ(無ければ null)
     * @return list<Row>
     */
    private function abandonedRows(array $abandoned): array
    {
        $rows = [];

        foreach ($abandoned as $package => $replacement) {
            $rows[] = new Row(Status::Action, [
                'composer の放棄', (string) $package, '放棄されたパッケージ', '', is_string($replacement) ? '代わり: '.$replacement : '代わりの指定なし',
            ]);
        }

        return $rows;
    }

    /**
     * @return list<Row>
     */
    private function npm(): array
    {
        $result = $this->commands->run(['npm', 'audit', '--json'], $this->root, 120);

        try {
            $output = Json::parse($result->stdout, 'npm audit');
            NpmErrors::throwIfError($output, 'npm audit');
            $vulnerabilities = Json::table($output, 'vulnerabilities', 'npm audit');

            return array_map(
                fn (array $vulnerability): Row => new Row(Status::Action, [
                    'npm の脆弱性',
                    Json::string($vulnerability, 'name', 'npm audit の脆弱性'),
                    $this->titles($vulnerability),
                    Json::string($vulnerability, 'severity', 'npm audit の脆弱性'),
                    $this->fix($vulnerability),
                ]),
                Json::tables($vulnerabilities, 'npm audit の vulnerabilities'),
            );
        } catch (UnconfirmedException $exception) {
            throw self::withStderr($exception, $result);
        }
    }

    /**
     * 確認できなかった理由に標準エラーを添える。空でない行をつなぎ、長ければ後ろの 300 字を残す(composer は原因の枠を使い方の行の
     * 直前に出し、前置きの通知は先頭に出るので、最後の行だけでも先頭からでも原因が落ちる)。
     */
    private static function withStderr(UnconfirmedException $exception, CommandResult $result): UnconfirmedException
    {
        $lines = $result->stderrLines();

        if ($lines === []) {
            return new UnconfirmedException("{$exception->getMessage()}(標準エラーが空)", previous: $exception);
        }

        $stderr = implode(' / ', $lines);
        $overflow = Text::length($stderr) - 300;

        if ($overflow > 0) {
            $stderr = '…'.substr($stderr, strlen(Text::cut($stderr, $overflow)));
        }

        return new UnconfirmedException("{$exception->getMessage()}(標準エラー: {$stderr})", previous: $exception);
    }

    /**
     * via は脆弱性の記述(表)か、他のパッケージ名(文字列)の並び。
     *
     * @param  array<array-key, mixed>  $vulnerability
     *
     * @throws UnconfirmedException
     */
    private function titles(array $vulnerability): string
    {
        $via = Json::optionalTable($vulnerability, 'via', 'npm audit の脆弱性') ?? [];
        $titles = array_map(
            static fn (mixed $item): string => is_array($item) ? Json::optionalString($item, 'title') : (string) (is_string($item) ? 'via '.$item : ''),
            $via,
        );

        return implode(' / ', array_filter($titles));
    }

    /**
     * @param  array<array-key, mixed>  $vulnerability
     *
     * @throws UnconfirmedException
     */
    private function fix(array $vulnerability): string
    {
        $fix = $vulnerability['fixAvailable'] ?? false;

        if (! is_bool($fix) && ! is_array($fix)) {
            throw new UnconfirmedException('npm audit の脆弱性の fixAvailable が真偽値でも表でもない');
        }

        if (is_array($fix)) {
            return Json::optionalString($fix, 'name').'@'.Json::optionalString($fix, 'version');
        }

        return $fix === true ? '修正版あり' : '修正版なし';
    }
}
