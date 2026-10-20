<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Queries\Records\FieldValueParser;
use App\Queries\Records\InvalidFieldValue;

/**
 * レコードの入力の値を読む(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 3・4): 項目に無いキーと、明細の項目のキーを values に
 * 置いたものを拒み、値を型ごとに検証して正規化する(2 節)。参照は、今と違う相手を指すときだけ 5 節の規則で確かめる
 * (複数選べる参照は、今の値に無かった相手だけ。誤りのキーは相手の添字 = 失敗と定義の変更.md 10 節)。
 */
class RecordInputReader
{
    /** 複数選べる参照の相手の数の上限(項目と規則/README.md 5 節) */
    public const MAX_MULTIPLE_TARGETS = 100;

    public function __construct(
        private FieldValueParser $parser,
        private ReferenceTargets $targets,
    ) {}

    /**
     * 入力の値を読んで返し(項目のコード → 正規の形の値。読めた値だけ)、失敗を $errors に足す(キー → 最初の文言)。作業中の値に入れるのは
     * {@see RecordValidator::merge()}(書き手を 1 か所にする = M01)。
     *
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    public function read(RecordDraft $draft, ReferenceCheck $check, array &$errors): array
    {
        $input = [];

        foreach ($draft->rawValues as $code => $value) {
            $code = (string) $code;
            $field = $draft->module->recordFields->first(fn (RecordField $field) => $field->code === $code);
            $key = $draft->key($code);

            if ($field === null || $field->type === RecordFieldType::Details) {
                $errors[$key] ??= $field === null ? '項目がありません' : "{$field->name}は明細(details)で指定してください";

                continue;
            }

            try {
                $input[$code] = match (true) {
                    $field->is_multiple => $this->references($draft, $field, $value, $check, $errors),
                    $field->type->isReference() => $this->reference($draft, $field, $value, $check),
                    default => $this->parser->parse($field, $value),
                };
            } catch (InvalidFieldValue $exception) {
                $errors[$key] ??= $exception->getMessage();
            }
        }

        return $input;
    }

    /**
     * 行の入力のうち、参照の項目が今と違う相手を新しく指すもの(まとめて確かめるため = {@see ReferenceTargets::prepare()})。
     * 複数選べる参照は、形の確かめを通る値の相手のうち今の値に無いもの(通らない値は集めない = 大きさを確かめる前の入力を問い合わせに使わない)。
     *
     * @return list<array{RecordField, int}>
     */
    public function changedReferences(RecordDraft $draft): array
    {
        $changed = [];

        foreach ($draft->rawValues as $code => $value) {
            $field = $draft->module->recordFields->first(fn (RecordField $field) => $field->code === (string) $code);

            if ($field === null) {
                continue;
            }

            foreach ($this->newTargets($draft, $field, $value) as $target) {
                $changed[] = [$field, $target];
            }
        }

        return $changed;
    }

    /**
     * 項目の入力のうち、今の値に無い相手の id(参照でない項目は空)。
     *
     * @return list<int>
     */
    private function newTargets(RecordDraft $draft, RecordField $field, mixed $value): array
    {
        if (! $field->type->isReference()) {
            return [];
        }

        $shape = $field->is_multiple ? $this->multipleShape($field, $value) : [$value];
        $current = $draft->record?->referenceValue($field);
        $isNew = fn (mixed $id) => is_int($id) && ! $this->isCurrent($current, $id);

        return is_string($shape) ? [] : array_values(array_filter($shape, $isNew));
    }

    /**
     * 参照の値(相手の id か null)。今と同じ相手は確かめ直さない。
     *
     * @throws InvalidFieldValue 整数でない・5 節の規則に合わないとき
     */
    private function reference(RecordDraft $draft, RecordField $field, mixed $value, ReferenceCheck $check): ?int
    {
        if ($value === null) {
            return null;
        }

        $current = $draft->record?->referenceValue($field);
        $isAccepted = is_int($value)
            && ($this->isCurrent($current, $value) || $this->accepts($draft, $field, $value, $check));

        if (! $isAccepted) {
            throw new InvalidFieldValue("{$field->name}の値が正しくありません");
        }

        return $value;
    }

    /**
     * 複数選べる参照の値(相手の id の配列。空の配列と null は null)。今の値に無かった相手だけを確かめ、合わない相手の誤りを
     * 添字のキーで $errors に足す。
     *
     * @param  array<string, string>  $errors
     * @return list<int>|null
     *
     * @throws InvalidFieldValue 配列でない・整数でない要素がある・上限を超える・同じ相手が 2 度あるとき
     */
    private function references(
        RecordDraft $draft,
        RecordField $field,
        mixed $value,
        ReferenceCheck $check,
        array &$errors,
    ): ?array {
        $ids = $this->multipleIds($field, $value);
        $current = $draft->record?->referenceValue($field);

        foreach ($ids ?? [] as $index => $id) {
            if (! $this->isCurrent($current, $id) && ! $this->accepts($draft, $field, $id, $check)) {
                $errors[$draft->key($field->code).".{$index}"] ??= "{$field->name}の値が正しくありません";
            }
        }

        return $ids;
    }

    /**
     * 複数選べる参照の入力の形を確かめる(2 節の表のユーザー(複数)・組織(複数)の行)。
     *
     * @return list<int>|null
     *
     * @throws InvalidFieldValue 形が違うとき
     */
    private function multipleIds(RecordField $field, mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        $shape = $this->multipleShape($field, $value);

        return is_string($shape) ? throw new InvalidFieldValue($shape) : $shape;
    }

    /**
     * 複数選べる参照の入力の形の規則の 1 か所(例外を投げない。参照先を確かめる前に大きさを見る newTargets も使う)。null は形の誤り。
     *
     * @return list<int>|string 形が合えば相手の id、合わなければ誤りの文言
     */
    private function multipleShape(RecordField $field, mixed $value): array|string
    {
        if (! is_array($value) || ! array_is_list($value) || ! array_all($value, fn (mixed $id) => is_int($id))) {
            return "{$field->name}の値が正しくありません";
        }

        $max = self::MAX_MULTIPLE_TARGETS;

        return match (true) {
            count($value) > $max => "{$field->name}は {$max} 件までにしてください",
            count(array_unique($value)) !== count($value) => "{$field->name}に同じ値が重複しています",
            default => $value,
        };
    }

    private function accepts(RecordDraft $draft, RecordField $field, int $targetId, ReferenceCheck $check): bool
    {
        return $this->targets->accepts($field, $targetId, $check, $draft->record?->id, $draft->parentRecordId);
    }

    /**
     * 今の値({@see Record::referenceValue()}。項目ごとに 1 回求めて渡す)が同じ相手を指しているか(複数選べる参照は、今の値のどれかが同じ相手)。
     *
     * @param  int|list<int>|null  $current
     */
    private function isCurrent(int|array|null $current, int $targetId): bool
    {
        return is_array($current) ? in_array($targetId, $current, true) : $current === $targetId;
    }
}
