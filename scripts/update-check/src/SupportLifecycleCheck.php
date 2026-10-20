<?php

namespace UpdateCheck;

use DateTimeImmutable;

/**
 * #7 サポート期限と新しい系列(endoflife.date)。期限切れ・90 日以内は要対応、今より新しい系列があれば更新あり、それ以外は表示だけ。
 * 日付はすべて UTC の暦日で読み、UTC の今日と比べる。
 */
final readonly class SupportLifecycleCheck implements Check
{
    public const WARNING_DAYS = 90;

    /** Trivy が読んだ OS → [endoflife.date の製品, 系列を取り出す正規表現, 表示名] */
    private const OPERATING_SYSTEMS = [
        'debian' => ['debian', '/^(\d+)/', 'Debian'],
        'alpine' => ['alpine-linux', '/^(\d+\.\d+)/', 'Alpine'],
    ];

    /** 表示名 → endoflife.date の製品(系列を読む場所は {@see run()}) */
    public const PRODUCTS = [
        'PHP' => 'php',
        'Node.js' => 'nodejs',
        'Laravel' => 'laravel',
        'React' => 'react',
        'PostgreSQL' => 'postgresql',
    ];

    private DateTimeImmutable $today;

    public function __construct(
        private SeriesReader $series,
        private RuntimeVersions $runtime,
        private ScanOutcome $scan,
        private EolClient $eol,
        DateTimeImmutable $now,
    ) {
        $this->today = Dates::today($now);
    }

    /**
     * この項目が endoflife.date に照会する製品(先取りの一覧の元。別に書かない)。
     *
     * @return list<string>
     */
    public static function products(): array
    {
        return [...array_values(self::PRODUCTS), ...array_column(self::OPERATING_SYSTEMS, 0)];
    }

    public function run(): Section
    {
        $builder = new SectionBuilder('#7', 'サポート期限と新しい系列', ['製品', '今の系列', '期限', '残り', '新しい系列']);

        $series = [
            'PHP' => $this->series->php(...),
            'Node.js' => $this->series->node(...),
            'Laravel' => $this->series->laravel(...),
            'React' => $this->series->react(...),
            'PostgreSQL' => $this->postgres(...),
        ];

        foreach (self::PRODUCTS as $label => $product) {
            $builder->attempt($label, fn (): array => [$this->row($label, $product, $series[$label]())]);
        }

        $this->operatingSystems($builder);

        return $builder->build();
    }

    /**
     * 固定したイメージごとに OS の系列を調べる。検査が確認できなかったイメージと、OS を判定できない(Trivy が読めない・対応表に無い)イメージは、そのイメージで 1 件の確認できなかった。
     */
    private function operatingSystems(SectionBuilder $builder): void
    {
        foreach ($this->scan->failures as $failure) {
            $builder->unconfirmed("OS({$failure->subject})", '#5 が確認できなかった');
        }

        foreach ($this->scan->results as $result) {
            [$product, $pattern, $name] = self::OPERATING_SYSTEMS[$result->osFamily] ?? [null, null, null];

            if ($product === null) {
                $builder->unconfirmed("OS({$result->image})", $result->osFamily === '' ? 'Trivy が OS を読めなかった' : "対応表に無い OS: {$result->osFamily}");

                continue;
            }

            $label = "{$name}({$result->image})";
            $builder->attempt($label, fn (): array => [$this->row($label, $product, $this->osSeries($result, $pattern ?? ''))]);
        }
    }

    /**
     * @throws UnconfirmedException
     */
    private function postgres(): string
    {
        if (isset($this->runtime->reasons['PostgreSQL'])) {
            throw new UnconfirmedException($this->runtime->reasons['PostgreSQL']);
        }

        return explode('.', $this->runtime->versions['PostgreSQL'] ?? '')[0];
    }

    /**
     * @throws UnconfirmedException
     */
    private function osSeries(ScanResult $result, string $pattern): string
    {
        if (preg_match($pattern, $result->osName, $match) !== 1) {
            throw new UnconfirmedException("OS の版から系列を読めない: {$result->osName}");
        }

        return $match[1];
    }

    /**
     * @throws UnconfirmedException
     */
    private function row(string $label, string $product, string $series): Row
    {
        $cycle = $this->eol->cycle($product, $series);
        $eolFrom = $cycle->eolFrom();
        $remaining = $eolFrom === null ? null : Dates::daysBetween($this->today, $eolFrom);
        $newer = $this->newer($product, $series);
        $expiring = $cycle->isEol() || ($remaining !== null && $remaining <= self::WARNING_DAYS);
        $status = match (true) {
            $expiring => Status::Action,
            $newer !== null => Status::Update,
            default => Status::Info,
        };

        return new Row($status, [$label, $series, $eolFrom?->format('Y-m-d') ?? '(なし)', $this->remainingText($cycle->isEol(), $remaining), $newer ?? '(なし)']);
    }

    private function remainingText(bool $isEol, ?int $remaining): string
    {
        return match (true) {
            $isEol || ($remaining !== null && $remaining < 0) => '期限切れ',
            $remaining === null => '-',
            default => "あと {$remaining} 日",
        };
    }

    /**
     * 今より新しい系列のうち、公開済みでいちばん新しいもの。
     *
     * @throws UnconfirmedException
     */
    private function newer(string $product, string $series): ?string
    {
        $newest = null;

        foreach ($this->eol->cycles($product) as $cycle) {
            if ($this->isNewerReleased($cycle, $series) && ($newest === null || version_compare($cycle->name, $newest, '>'))) {
                $newest = $cycle->name;
            }
        }

        return $newest;
    }

    /**
     * 数字の系列で今より大きいものだけ、公開日を確かめる(使わない系列の不備で製品ごと確認できなくしない)。
     *
     * @throws UnconfirmedException
     */
    private function isNewerReleased(EolCycle $cycle, string $series): bool
    {
        return preg_match('/^\d+(\.\d+)*$/D', $cycle->name) === 1
            && version_compare($cycle->name, $series, '>')
            && $cycle->releaseDate() <= $this->today;
    }
}
