<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RelativeDefault;
use App\Queries\Records\FieldValueParser;
use App\Queries\Records\InvalidFieldValue;
use Carbon\CarbonImmutable;

/**
 * 合わせた値を作って検証する(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 5〜8)。
 *
 * 5 = 登録は入力 + 既定値(入力にキーの無い項目だけ。真偽は既定値が無ければ 6 の parse で偽になる = 3 節)、更新は今の値に入力を重ねたもの。
 * 合わせた値は今の定義の項目だけを持つ(定義に無いキーは読まない)。6 = 入力で変えなかった値も今の定義で 2 節の検証に掛ける
 * (参照の有効性は確かめ直さない)。7・8 = {@see RuleEvaluator}。6 で型の形に合わない値の項目を対象・相手・条件に使う規則は、7・8 で評価しない
 * (その項目の誤りは 6 で返る。ほかの規則は評価して一緒に返す = M01、9.2 の 6〜8 の注記)。
 *
 * 作業中の値({@see RecordDraft} の input・values)を書くのはこのクラスだけ(M01。読むのは {@see RuleEvaluator} と {@see RecordWriter})。
 */
class RecordValidator
{
    public function __construct(
        private FieldValueParser $parser,
        private RuleEvaluator $rules,
    ) {}

    /**
     * 読んだ入力({@see RecordInputReader::read()} の結果)を $draft->input に入れ、合わせた値を $draft->values に作る(9.2 の 5)。
     * 相対の既定値は $now から値にする(親と明細の行に同じ時点を渡す = 項目と規則/README.md 3 節)。
     *
     * @param  array<string, mixed>  $input  項目のコード → 正規の形の値
     * @param  CarbonImmutable  $now  登録の時点(時計の今を 1 回の実行で 1 回読んだもの)
     */
    public function merge(RecordDraft $draft, array $input, CarbonImmutable $now): void
    {
        $draft->input = $input;
        $current = $this->currentValues($draft);

        foreach ($draft->module->recordFields as $field) {
            if ($field->type === RecordFieldType::Details) {
                continue;
            }

            $draft->values[$field->code] = match (true) {
                array_key_exists($field->code, $draft->input) => $draft->input[$field->code],
                $draft->record !== null => $current[$field->code] ?? null,
                $field->default_value instanceof RelativeDefault => $field->default_value->valueAt($now, $field->type),
                default => $field->default_value,
            };
        }
    }

    /**
     * 合わせた値の検証(9.2 の 6〜8)。失敗を $errors に足す(キー → 最初の文言)。
     * 入力に無い通常の項目の値をここで parse し直すので、この後(規則の評価)は真偽がいつも bool(既定値の無い新しい行の真偽も null → 偽)。
     * parse に失敗した値は元のまま残り、誤りが返る。型の形に合わない値の項目は規則の評価から外す(長さ・選択肢だけの誤りは外さない)。
     *
     * @param  array<string, int>  $detailCounts  明細の項目のコード → 保存後に残る行の数
     * @param  array<string, string>  $errors
     */
    public function validate(RecordDraft $draft, array $detailCounts, array &$errors): void
    {
        $wrongShapeCodes = [];

        foreach ($draft->module->recordFields as $field) {
            if (! $field->type->isPlain() || array_key_exists($field->code, $draft->input)) {
                continue;
            }

            try {
                $draft->values[$field->code] = $this->parser->parse($field, $draft->values[$field->code] ?? null);
            } catch (InvalidFieldValue $exception) {
                $errors[$draft->key($field->code)] ??= $exception->getMessage();

                if ($exception->isWrongShape) {
                    $wrongShapeCodes[] = $field->code;
                }
            }
        }

        $this->rules->check($draft, $detailCounts, $errors, $wrongShapeCodes);
    }

    /**
     * 今の行の値(項目のコード → 値。参照は相手の id、複数選べる参照は id の配列。新しい行なら空)。
     *
     * @return array<string, mixed>
     */
    private function currentValues(RecordDraft $draft): array
    {
        $record = $draft->record;

        if ($record === null) {
            return [];
        }

        $values = [];

        foreach ($draft->module->recordFields as $field) {
            $values[$field->code] = $field->type->isReference()
                ? $record->referenceValue($field)
                : $this->parser->stored($field, $record->field_values ?? []);
        }

        return $values;
    }
}
