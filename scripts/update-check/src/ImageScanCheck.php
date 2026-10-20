<?php

namespace UpdateCheck;

/**
 * #5 実行に使うイメージの脆弱性(重大度 HIGH・CRITICAL)。
 * 固定したイメージにあり、新しい土台の検査に同じ脆弱性(ID とパッケージ)が無ければ要対応(土台を上げれば直る)。
 * それ以外は一覧に出すだけで、先頭行の「土台で未修正の脆弱性」に数える。分類 = 修正版はあるが新しい土台でも残る / 修正版なし / 新しい土台なし /
 * 新しい土台の検査が確認できなかった(新しい土台はあるかもしれないが build・検査か #4 の照会が失敗した。要対応にしない)。
 */
final readonly class ImageScanCheck implements Check
{
    private const SEVERITY_ORDER = ['CRITICAL' => 0, 'HIGH' => 1];

    private const FIXED_BY_UPGRADE = '上げれば直る';

    private const REMAINS_WITH_FIX = '修正版はあるが新しい土台でも残る';

    private const NO_FIX = '修正版なし';

    private const NO_NEW_BASE = '新しい土台なし';

    private const UNVERIFIED = '新しい土台の検査が確認できなかった';

    public function __construct(private ScanOutcome $outcome) {}

    public function run(): Section
    {
        $builder = new SectionBuilder('#5', '実行に使うイメージの脆弱性', ['イメージ', '分類', 'パッケージ', '脆弱性', '重大度', '現在', '修正版']);

        foreach ($this->outcome->results as $result) {
            $builder->attempt($result->image, fn (): array => $this->rows($result));
        }

        foreach ([...$this->outcome->failures, ...array_values($this->outcome->newFailures)] as $failure) {
            $builder->unconfirmed($failure->subject, $failure->reason);
        }

        return $builder->build();
    }

    /**
     * @return list<Row>
     */
    private function rows(ScanResult $result): array
    {
        if ($result->vulnerabilities === []) {
            return [new Row(Status::Info, [$result->image, '(なし)', '', '', '', '', ''])];
        }

        $items = array_map(fn (Vulnerability $item): array => [$this->kind($result, $item), $item], $result->vulnerabilities);
        usort($items, fn (array $a, array $b): int => $this->sortKey($a) <=> $this->sortKey($b));

        return array_map(
            static fn (array $entry): Row => new Row(
                $entry[0] === self::FIXED_BY_UPGRADE ? Status::Action : Status::Unfixed,
                [$result->image, $entry[0], $entry[1]->package, $entry[1]->id, $entry[1]->severity, $entry[1]->installed, $entry[1]->fixed ?? '(なし)'],
            ),
            $items,
        );
    }

    private function kind(ScanResult $result, Vulnerability $item): string
    {
        $new = $this->outcome->newScans[$result->image] ?? null;

        return match (true) {
            in_array($result->image, $this->outcome->unverified(), true) => self::UNVERIFIED,
            $new === null => self::NO_NEW_BASE,
            ! $new->has($item) => self::FIXED_BY_UPGRADE,
            $item->fixed !== null => self::REMAINS_WITH_FIX,
            default => self::NO_FIX,
        };
    }

    /**
     * 分類 → 重大度 → ID の順。
     *
     * @param  array{0: string, 1: Vulnerability}  $entry
     * @return array{0: int, 1: int, 2: string}
     */
    private function sortKey(array $entry): array
    {
        $order = [self::FIXED_BY_UPGRADE, self::REMAINS_WITH_FIX, self::NO_FIX, self::NO_NEW_BASE, self::UNVERIFIED];

        return [(int) array_search($entry[0], $order, true), self::SEVERITY_ORDER[$entry[1]->severity] ?? 9, $entry[1]->id];
    }
}
