<?php

namespace App\Queries\Records;

/**
 * 更新履歴の版の写し 2 つの参照制限(snapshot の row_access)の差分。版を作るかの比べ方と画面の差分が同じ読み方を使う 1 か所
 * (docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2 節、形 = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md
 * の 2.1.1)。移しより前の版は row_access を持たないので、形の違う値は例外にせず無い値として読む。
 *
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type PartyValue from RecordValueReader
 *
 * @phpstan-type RowAccessChange array{restricted: array{before: bool, after: bool}|null,
 *     viewers: array{before: list<ReferenceTarget>, after: list<ReferenceTarget>}|null}
 * @phpstan-type ShownRowAccessChange array{restricted: array{before: bool, after: bool}|null,
 *     viewers: array{before: list<PartyValue>, after: list<PartyValue>}|null}
 */
class RecordRevisionRowAccess
{
    public function __construct(private RecordValueReader $reader) {}

    /**
     * @param  mixed  $before  前の版の snapshot
     * @param  mixed  $after  後の版の snapshot
     * @return RowAccessChange|null 変化が無い・どちらかの版に記録が無ければ null
     */
    public function compare(mixed $before, mixed $after): ?array
    {
        [$from, $to] = [$this->read($before), $this->read($after)];

        if ($from === null || $to === null) {
            return null;
        }

        $restricted = $from['restricted'] === $to['restricted']
            ? null
            : ['before' => $from['restricted'], 'after' => $to['restricted']];
        $viewers = $this->keySet($from['viewers']) === $this->keySet($to['viewers'])
            ? null
            : ['before' => $from['viewers'], 'after' => $to['viewers']];

        return $restricted === null && $viewers === null ? null : ['restricted' => $restricted, 'viewers' => $viewers];
    }

    /**
     * 差分に出る参照者の相手(表示名をまとめて引くため)。
     *
     * @param  RowAccessChange|null  $change
     * @return list<ReferenceTarget>
     */
    public function targets(?array $change): array
    {
        return [...$change['viewers']['before'] ?? [], ...$change['viewers']['after'] ?? []];
    }

    /**
     * 画面の値(参照者は相手の種類と参照の値 = 型「ユーザーと組織」の値と同じ形。今は無い相手は id を残して code・label が null)。
     *
     * @param  RowAccessChange|null  $change
     * @param  array<string, ReferenceValue>  $labels  {@see RecordValueReader::targetValues()} の結果
     * @return ShownRowAccessChange|null
     */
    public function shown(?array $change, array $labels): ?array
    {
        if ($change === null) {
            return null;
        }

        $viewers = $change['viewers'];

        return [
            'restricted' => $change['restricted'],
            'viewers' => $viewers === null ? null : [
                'before' => $this->parties($viewers['before'], $labels),
                'after' => $this->parties($viewers['after'], $labels),
            ],
        ];
    }

    /**
     * @param  list<ReferenceTarget>  $targets
     * @param  array<string, ReferenceValue>  $labels
     * @return list<PartyValue>
     */
    private function parties(array $targets, array $labels): array
    {
        return array_map(function (array $target) use ($labels): array {
            $value = $labels[$this->reader->targetKey($target)]
                ?? ['id' => $target['id'], 'code' => null, 'label' => null, 'affiliation' => null];

            return ['type' => $target['type'], ...$value];
        }, $targets);
    }

    /**
     * 写しの row_access(無い・チェックが真偽でなければ null。参照者は相手の形の要素だけ)。
     *
     * @return array{restricted: bool, viewers: list<ReferenceTarget>}|null
     */
    private function read(mixed $snapshot): ?array
    {
        $rowAccess = is_array($snapshot) ? ($snapshot['row_access'] ?? null) : null;

        if (! is_array($rowAccess) || ! is_bool($rowAccess['restricted'] ?? null)) {
            return null;
        }

        return ['restricted' => $rowAccess['restricted'], 'viewers' => $this->viewers($rowAccess['viewers'] ?? null)];
    }

    /**
     * 写しの参照者のうち相手の形の要素(相手の形 = {@see RecordParty::of()})。
     *
     * @return list<ReferenceTarget>
     */
    private function viewers(mixed $raw): array
    {
        $parties = array_map(fn (mixed $viewer) => RecordParty::of($viewer), is_array($raw) ? $raw : []);

        return array_values(array_filter($parties, fn (?array $party) => $party !== null));
    }

    /**
     * 相手の集合(並びによらない比べのための鍵の並び)。
     *
     * @param  list<ReferenceTarget>  $targets
     * @return list<string>
     */
    private function keySet(array $targets): array
    {
        $keys = array_map($this->reader->targetKey(...), $targets);
        sort($keys);

        return $keys;
    }
}
