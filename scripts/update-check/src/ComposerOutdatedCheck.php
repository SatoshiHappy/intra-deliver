<?php

namespace UpdateCheck;

/**
 * #1 composer の直接依存の新しい版。lock から読み(vendor が無くてよい)、PHP と拡張の制約は外す(入れられるかは取り込みの作業で判断する)。
 */
final readonly class ComposerOutdatedCheck implements Check
{
    public function __construct(
        private CommandRunner $commands,
        private string $root,
    ) {}

    public function run(): Section
    {
        $builder = new SectionBuilder('#1', 'composer の直接依存の新しい版', ['パッケージ', '現在', '最新', '状況']);
        $builder->attempt('composer outdated', $this->rows(...));

        return $builder->build();
    }

    /**
     * @return list<Row>
     */
    private function rows(): array
    {
        $result = $this->commands->run(
            ['composer', 'outdated', '--locked', '--direct', '--format=json', '--ignore-platform-reqs', '--no-interaction'],
            $this->root,
            120,
        );
        $packages = Json::table(Json::parse($result->stdout, 'composer outdated'), 'locked', 'composer outdated');

        return array_map(
            static fn (array $package): Row => new Row(Status::Update, [
                Json::string($package, 'name', 'composer outdated の行'),
                Json::string($package, 'version', 'composer outdated の行'),
                Json::string($package, 'latest', 'composer outdated の行'),
                Json::optionalString($package, 'latest-status'),
            ]),
            Json::tables($packages, 'composer outdated の locked'),
        );
    }
}
