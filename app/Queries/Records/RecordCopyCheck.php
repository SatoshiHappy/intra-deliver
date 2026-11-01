<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 写しの初期値の確かめ({@see RecordCopyValues} の中身。docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/写す値.md の
 * 13.3)。参照の相手・参照者({@see ReferenceTargets})と選択肢({@see FieldValueParser::hasOption()})は保存と同じ 1 か所を呼ぶ。
 * 呼び手が {@see self::prepare()} で全部の相手をまとめて確かめてから、{@see self::kept()}・{@see self::keptRowAccess()} で引く。
 *
 * @phpstan-import-type RawValues from RecordValueReader
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type StoredRowAccess from RecordRowAccessValues
 * @phpstan-import-type CopySource from RecordCopySources
 * @phpstan-import-type Party from RecordParty
 */
class RecordCopyCheck
{
    public function __construct(private ReferenceTargets $targets, private FieldValueParser $parser) {}

    /**
     * 写す相手を種類ごとにまとめて確かめる(親・明細の全行の参照の相手と、参照者)。
     *
     * @param  CopySource  $source
     */
    public function prepare(ReferenceCheck $check, array $source): void
    {
        $wanted = $this->wanted($source['fields'], $source['raw']);

        foreach ($source['details'] as $detail) {
            foreach ($detail['rows'] as $row) {
                array_push($wanted, ...$this->wanted($detail['fields'], $row));
            }
        }

        $this->targets->prepare($check, $wanted);

        if ($source['rowAccess'] !== null) {
            $this->targets->prepareParties($check, $source['rowAccess']['viewers']);
        }
    }

    /**
     * @param  Collection<int, RecordField>  $fields
     * @param  RawValues  $raw
     * @return list<array{RecordField, int|Party}>
     */
    private function wanted(Collection $fields, array $raw): array
    {
        $wanted = [];

        foreach ($fields as $field) {
            foreach ($raw['references'][$field->code] ?? [] as $target) {
                $wanted[] = [$field, self::argumentOf($field, $target)];
            }
        }

        return $wanted;
    }

    /**
     * 確かめを通る値だけを残した値と、空にした・相手を除いた項目のコード。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  RawValues  $raw
     * @return array{RawValues, list<string>}
     */
    public function kept(Collection $fields, array $raw, ReferenceCheck $check, ?int $selfId, ?int $parentId): array
    {
        $cleared = [];

        foreach ($fields as $field) {
            if ($this->clears($field, $raw, $check, $selfId, $parentId)) {
                $cleared[] = $field->code;
            }
        }

        return [$raw, $cleared];
    }

    /**
     * 項目 1 つの値から確かめを通らない値・相手を外し、外したかを返す。
     *
     * @param  RawValues  $raw
     */
    private function clears(RecordField $field, array &$raw, ReferenceCheck $check, ?int $selfId, ?int $parentId): bool
    {
        $code = $field->code;

        if ($field->type->isReference()) {
            $targets = $raw['references'][$code] ?? [];
            $raw['references'][$code] = array_values(array_filter(
                $targets,
                fn (array $target) => $this->targets->accepts(
                    $field, self::argumentOf($field, $target), $check, $selfId, $parentId,
                ),
            ));

            return count($raw['references'][$code]) !== count($targets);
        }

        $value = $raw['values'][$code] ?? null;
        $isCleared = $field->type === RecordFieldType::Choice && ! $this->isOption($field, $value);
        $raw['values'][$code] = $isCleared ? null : $value;

        return $isCleared;
    }

    /**
     * 選択の値が項目の選択肢にあるか(未設定は通り、文字列でない値は通らない)。
     */
    private function isOption(RecordField $field, mixed $value): bool
    {
        return $value === null || (is_string($value) && $this->parser->hasOption($field, $value));
    }

    /**
     * 参照制限から、日に有効でない参照者を外した値と、外したか。
     *
     * @param  StoredRowAccess|null  $rowAccess
     * @return array{StoredRowAccess|null, bool}
     */
    public function keptRowAccess(?array $rowAccess, ReferenceCheck $check): array
    {
        if ($rowAccess === null) {
            return [null, false];
        }

        $viewers = $rowAccess['viewers'];
        $kept = array_values(array_filter(
            $viewers,
            fn (array $viewer) => $this->targets->acceptsParty($viewer, $check),
        ));

        return [['restricted' => $rowAccess['restricted'], 'viewers' => $kept], count($kept) !== count($viewers)];
    }

    /**
     * 確かめの口に渡す相手の形(型「ユーザーと組織」= 相手、ほかの参照の型 = id)。
     *
     * @param  ReferenceTarget  $target
     * @return int|Party
     */
    private static function argumentOf(RecordField $field, array $target): int|array
    {
        if (! $field->type->hasPartyKind()) {
            return $target['id'];
        }

        return RecordParty::of($target) ?? throw new LogicException("型「ユーザーと組織」の相手の形でない: {$field->code}");
    }
}
