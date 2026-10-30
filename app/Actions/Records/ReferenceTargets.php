<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordParty;
use App\Queries\Records\RecordRowAccess;
use LogicException;

/**
 * 参照の項目が新しく指す相手を確かめる(docs の 設計/業務データの入れ物/項目と規則/README.md 5 節の書くときの規則)。
 * ユーザー・組織 = d に有効なもの(型「ユーザーと組織」は相手の種類ごとに同じ規則)。他モジュール参照 = 項目の参照先の業務モジュールのレコードで、判定を求める者がその業務モジュールの参照を
 * 許されていて、レコードが行の判定を通る(候補と同じ判定 = 認可とメニュー/README.md 2.2 の「参照の項目」)。自分自身と、明細の行なら自分の親の
 * レコードは指せない。
 *
 * 確かめは {@see self::prepare()} で、1 回の保存の全部の相手を種類ごとにまとめて行う(判定は参照先の業務モジュールごとに 1 回、
 * 有無は whereIn 1 本。相手の数だけ問い合わせない)。結果は {@see ReferenceCheck} に入れ、{@see self::accepts()} が引く。
 * 相手の種類(ユーザー・組織・参照先の業務モジュール)は {@see self::kindOf()} が項目の型ごとに決める(型「ユーザーと組織」は相手の種類。
 * 型の分岐は既定の枝に落とさず全部の型を挙げる = 新しい型を足したら静的解析が漏れを知らせる)。
 *
 * @phpstan-import-type Party from RecordParty
 */
class ReferenceTargets
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
    ) {}

    /**
     * 新しく指す相手をまとめて確かめ、結果を check に入れる。
     *
     * @param  list<array{RecordField, int|Party}>  $wanted  参照の項目と、新しく指す相手
     */
    public function prepare(ReferenceCheck $check, array $wanted): void
    {
        $fields = [];
        $kinds = [];
        $ids = [];

        foreach ($wanted as [$field, $target]) {
            [$kind, $id] = self::kindOf($field, $target);
            $group = self::groupOf($kind, $field);
            $fields[$group] = $field;
            $kinds[$group] = $kind;
            $ids[$group][] = $id;
        }

        foreach ($fields as $group => $field) {
            $asked = array_values(array_unique($ids[$group]));
            $check->remember($group, $asked, $this->acceptedIds($kinds[$group], $field, $asked, $check));
        }
    }

    /**
     * 新しく指す相手を受けるか({@see self::prepare()} で確かめた結果を引く。確かめていない相手は例外 = 確かめ漏れを黙って通さない)。
     *
     * @param  int|Party  $target
     * @param  int|null  $selfId  書くレコードの id(新しいレコードなら null)
     * @param  int|null  $parentId  明細の行の親のレコードの id
     */
    public function accepts(
        RecordField $field,
        int|array $target,
        ReferenceCheck $check,
        ?int $selfId,
        ?int $parentId,
    ): bool {
        [$kind, $id] = self::kindOf($field, $target);

        if ($kind === RecordFieldType::Record && in_array($id, [$selfId, $parentId], true)) {
            return false;
        }

        return $check->accepts(self::groupOf($kind, $field), $id);
    }

    /**
     * 相手の種類(ユーザー・組織・他モジュール参照のどれか)と id。型「ユーザーと組織」は相手の種類、ほかの参照の型は項目の型。
     *
     * @param  int|Party  $target
     * @return array{RecordFieldType, int}
     *
     * @throws LogicException 項目の型と相手の形が合わない・参照でない型のとき(入力の読み取りが形を確かめた後に来ない = 呼び手の誤り)
     */
    private static function kindOf(RecordField $field, int|array $target): array
    {
        $mismatch = new LogicException("参照の相手の形が項目の型と合わない(検証の後に来ない): {$field->code}");

        return match ($field->type) {
            RecordFieldType::User,
            RecordFieldType::Department,
            RecordFieldType::Record => is_int($target) ? [$field->type, $target] : throw $mismatch,
            RecordFieldType::UserOrDepartment => is_array($target)
                ? [RecordFieldType::from($target['type']), $target['id']]
                : throw $mismatch,
            RecordFieldType::String,
            RecordFieldType::Integer,
            RecordFieldType::Decimal,
            RecordFieldType::Date,
            RecordFieldType::Datetime,
            RecordFieldType::Boolean,
            RecordFieldType::Choice,
            RecordFieldType::Details => throw $mismatch,
        };
    }

    /**
     * 確かめの束の鍵(相手の種類と、他モジュール参照なら参照先の業務モジュール)。
     */
    private static function groupOf(RecordFieldType $kind, RecordField $field): string
    {
        return $kind->value.':'.($kind === RecordFieldType::Record ? $field->referenced_record_module_id : '');
    }

    /**
     * @param  RecordFieldType  $kind  {@see self::kindOf()} の相手の種類
     * @param  list<int>  $ids
     * @return list<int> 受ける相手の id
     *
     * @throws LogicException 相手の種類でない型のとき(kindOf が返さない)
     */
    private function acceptedIds(RecordFieldType $kind, RecordField $field, array $ids, ReferenceCheck $check): array
    {
        return match ($kind) {
            RecordFieldType::User => array_values(
                User::query()->whereKey($ids)->effectiveOn($check->date)->pluck('id')->all(),
            ),
            RecordFieldType::Department => array_values(
                Department::query()->whereKey($ids)->effectiveOn($check->date)->pluck('id')->all(),
            ),
            RecordFieldType::Record => $this->readableRecordIds($field, $ids, $check),
            RecordFieldType::UserOrDepartment,
            RecordFieldType::String,
            RecordFieldType::Integer,
            RecordFieldType::Decimal,
            RecordFieldType::Date,
            RecordFieldType::Datetime,
            RecordFieldType::Boolean,
            RecordFieldType::Choice,
            RecordFieldType::Details => throw new LogicException("相手の種類でない型: {$kind->value}"),
        };
    }

    /**
     * 相手のレコードの行に FOR KEY SHARE を取り、同時に走る削除(FOR UPDATE)と並べる。削除が先なら相手は無いとして 422、確かめが先なら
     * 削除が参照の行を見て 422 になり、どちらの順でも参照の行の書き込みが外部キー違反で落ちない(設計/業務データの入れ物/データモデル/値の形と直列化.md 4 節)。
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function readableRecordIds(RecordField $field, array $ids, ReferenceCheck $check): array
    {
        $requester = $check->requester();
        $module = $field->referencedRecordModule;

        if ($module === null || ! $this->gate->allows($requester, $module, RecordModuleAction::Read, $check->date)) {
            return [];
        }

        $records = Record::query()->where('record_module_id', $module->id)->whereKey($ids)->lock('for key share');

        return array_values($this->rows->constrain($records, $requester, $module, $check->date)->pluck('id')->all());
    }
}
