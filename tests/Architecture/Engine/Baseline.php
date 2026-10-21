<?php

namespace Tests\Architecture\Engine;

use JsonException;
use UnexpectedValueException;

/**
 * 今の違反の数を上限にするベースライン(汎用。照合 1・2 = docs の 設計/コード構成/検査/README.md 6.3)。値 = キー → 大きい順の並び(値が 1 つのキーは 1 要素)。
 */
final readonly class Baseline
{
    /**
     * @param  array<string, list<int>>  $entries
     * @param  bool  $sorted  読んだファイルのキーがバイトの昇順に並んでいたか(数えた値から作ったものは常に真)
     */
    private function __construct(private array $entries, private bool $sorted = true) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * 数えた値(キー → 数か数の並び)から作る。並びは大きい順にそろえる。
     *
     * @param  array<array-key, mixed>  $counts
     *
     * @throws UnexpectedValueException 値が 1 以上の整数でないとき
     */
    public static function of(array $counts): self
    {
        return new self(self::entriesOf($counts));
    }

    /**
     * ⑧ の検査 $check に baseline の行 $file(根 $root からの相対パス)があればそのファイルを読み、無ければ空(全部の違反で落ちる)。
     *
     * @throws UnexpectedValueException
     */
    public static function fromLedger(ExclusionLedger $ledger, string $check, string $root, string $file): self
    {
        if (! $ledger->registers([$check], 'baseline', $file)) {
            return self::empty();
        }

        $content = is_file($root.'/'.$file) ? file_get_contents($root.'/'.$file) : false;

        if ($content === false) {
            throw new UnexpectedValueException("⑧ {$check} の baseline の行が指すファイルを読めない: {$file}");
        }

        return self::fromJson($content, $file);
    }

    /**
     * @throws UnexpectedValueException JSON の表でないとき・値の形が違うとき
     */
    public static function fromJson(string $json, string $label): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                "ベースラインを JSON として読めない: {$label}({$exception->getMessage()})",
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new UnexpectedValueException("ベースラインの最上位がキーの表でない: {$label}");
        }

        $keys = array_map(strval(...), array_keys($decoded));
        $sortedKeys = $keys;
        sort($sortedKeys, SORT_STRING);

        return new self(self::entriesOf($decoded), $keys === $sortedKeys);
    }

    public function total(): int
    {
        return array_sum(array_map(fn (array $values): int => array_sum($values), $this->entries));
    }

    /**
     * 照合 1: 実際の数 $actual との違い(1 件 1 行。キー・ベースラインの値・実際の値・どちらを直すか)。一致すれば空。
     *
     * @return list<string>
     */
    public function mismatches(self $actual): array
    {
        $keys = LedgerMatch::equal(array_keys($actual->entries), array_keys($this->entries));
        $lines = [];

        foreach ($keys->extra as $key) {
            $actualValues = '実際 = '.self::show($actual->entries[$key]);
            $lines[] = self::line($key, 'ベースライン = なし', $actualValues, '違反を直す(ベースラインに無いキー)');
        }

        foreach ($keys->missing as $key) {
            $lines[] = self::line($key, 'ベースライン = '.self::show($this->entries[$key]), '実際 = なし', 'ベースラインの行を消す');
        }

        $lines = [...$lines, ...$this->changedValues($actual)];
        sort($lines, SORT_STRING);

        return $this->sorted ? $lines : ['(並び): キーがバイトの昇順に並んでいない → キーで並べる', ...$lines];
    }

    /**
     * 照合 2: 基準の版のベースライン $base から上げたところ(1 件 1 行)。無ければ空。
     *
     * @return list<string>
     */
    public function raisedFrom(self $base): array
    {
        $lines = [];

        foreach (array_intersect_key($this->entries, $base->entries) as $key => $values) {
            if (self::exceeds($values, $base->entries[$key])) {
                $baseValues = '基準 = '.self::show($base->entries[$key]);
                $lines[] = self::line($key, $baseValues, '今 = '.self::show($values), 'ベースラインを上げない');
            }
        }

        if ($this->total() > $base->total()) {
            $action = 'ベースラインを上げない(足したキーの分だけ、ほかのキーを下げていない)';
            $lines[] = self::line('(合計)', '基準 = '.$base->total(), '今 = '.$this->total(), $action);
        }

        return $lines;
    }

    /**
     * 大きい順の並び $values が $limits を超えるか(要素の数が多いか、同じ位置の要素が大きい)。
     *
     * @param  list<int>  $values
     * @param  list<int>  $limits
     */
    private static function exceeds(array $values, array $limits): bool
    {
        if (count($values) > count($limits)) {
            return true;
        }

        return array_any($values, fn (int $value, int $index): bool => $value > $limits[$index]);
    }

    private static function line(string $key, string $baseline, string $actual, string $action): string
    {
        return "{$key}: {$baseline}・{$actual} → {$action}";
    }

    /**
     * @param  array<array-key, mixed>  $counts
     * @return array<string, list<int>>
     *
     * @throws UnexpectedValueException
     */
    private static function entriesOf(array $counts): array
    {
        $entries = [];

        foreach ($counts as $key => $value) {
            $entries[(string) $key] = self::values((string) $key, $value);
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * 両方にあるキーのうち、値が違うもの(1 件 1 行)。
     *
     * @return list<string>
     */
    private function changedValues(self $actual): array
    {
        $lines = [];

        foreach (array_intersect_key($actual->entries, $this->entries) as $key => $values) {
            if ($values !== $this->entries[$key]) {
                $action = self::exceeds($values, $this->entries[$key]) ? '違反を直す' : 'ベースラインを実際の値に下げる';
                $baselineValues = 'ベースライン = '.self::show($this->entries[$key]);
                $lines[] = self::line($key, $baselineValues, '実際 = '.self::show($values), $action);
            }
        }

        return $lines;
    }

    /**
     * @param  list<int>  $values
     */
    private static function show(array $values): string
    {
        return count($values) === 1 ? (string) $values[0] : '['.implode(', ', $values).']';
    }

    /**
     * @return list<int>
     *
     * @throws UnexpectedValueException
     */
    private static function values(string $key, mixed $value): array
    {
        $message = "ベースラインの {$key} の値は 1 以上の整数か、1 以上の整数の並び: ".json_encode($value);
        $items = is_int($value) ? [$value] : $value;

        if (! is_array($items) || $items === [] || ! array_is_list($items)) {
            throw new UnexpectedValueException($message);
        }

        $values = [];

        foreach ($items as $item) {
            if (! is_int($item) || $item < 1) {
                throw new UnexpectedValueException($message);
            }

            $values[] = $item;
        }

        rsort($values, SORT_NUMERIC);

        return $values;
    }
}
