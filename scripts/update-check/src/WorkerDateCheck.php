<?php

namespace UpdateCheck;

use DateTimeImmutable;

/**
 * #8 Workers の compatibility_date(deploy/worker/wrangler.jsonc)からの経過日数。表示だけ。UTC の暦日で数える。
 */
final readonly class WorkerDateCheck implements Check
{
    public const FILE = 'deploy/worker/wrangler.jsonc';

    private DateTimeImmutable $today;

    public function __construct(
        private string $root,
        DateTimeImmutable $now,
    ) {
        $this->today = Dates::today($now);
    }

    public function run(): Section
    {
        $builder = new SectionBuilder('#8', 'Workers の compatibility_date', ['ファイル', '日付', '経過日数']);
        $builder->attempt(self::FILE, fn (): array => [$this->row()]);

        return $builder->build();
    }

    /**
     * @throws UnconfirmedException
     */
    private function row(): Row
    {
        $text = @file_get_contents($this->root.'/'.self::FILE);

        if ($text === false || preg_match('/"compatibility_date"\s*:\s*"([^"]*)"/', $text, $match) !== 1) {
            throw new UnconfirmedException('compatibility_date を読めない');
        }

        $date = Dates::parse($match[1], 'compatibility_date');

        return new Row(Status::Info, [self::FILE, $match[1], (string) Dates::daysBetween($date, $this->today)]);
    }
}
