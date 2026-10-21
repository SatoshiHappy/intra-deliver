<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;

/**
 * 参照の項目が新しく指す相手を確かめる(docs の 設計/業務データの入れ物/項目と規則/README.md 5 節の書くときの規則)。
 * ユーザー・組織 = d に有効なもの。他モジュール参照 = 項目の参照先の業務モジュールのレコードで、判定を求める者がその業務モジュールの参照を
 * 許されていて、レコードが行の判定を通る(候補と同じ判定 = 認可とメニュー/README.md 2.2 の「参照の項目」)。自分自身と、明細の行なら自分の親の
 * レコードは指せない。
 *
 * 確かめは {@see self::prepare()} で、1 回の保存の全部の相手を種類ごとにまとめて行う(判定は参照先の業務モジュールごとに 1 回、
 * 有無は whereIn 1 本 = 修正方針 A6)。結果は {@see ReferenceCheck} に入れ、{@see self::accepts()} が引く。
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
     * @param  list<array{RecordField, int}>  $wanted  参照の項目と、新しく指す相手の id
     */
    public function prepare(ReferenceCheck $check, array $wanted): void
    {
        $fields = [];
        $ids = [];

        foreach ($wanted as [$field, $targetId]) {
            $group = self::groupOf($field);
            $fields[$group] = $field;
            $ids[$group][] = $targetId;
        }

        foreach ($fields as $group => $field) {
            $asked = array_values(array_unique($ids[$group]));
            $check->remember($group, $asked, $this->acceptedIds($field, $asked, $check));
        }
    }

    /**
     * 新しく指す相手を受けるか({@see self::prepare()} で確かめた結果を引く。確かめていない相手は例外 = 修正方針 D5)。
     *
     * @param  int|null  $selfId  書くレコードの id(新しいレコードなら null)
     * @param  int|null  $parentId  明細の行の親のレコードの id
     */
    public function accepts(RecordField $field, int $targetId, ReferenceCheck $check, ?int $selfId, ?int $parentId): bool
    {
        if ($field->type === RecordFieldType::Record && in_array($targetId, [$selfId, $parentId], true)) {
            return false;
        }

        return $check->accepts(self::groupOf($field), $targetId);
    }

    /**
     * 相手の種類(ユーザー・組織・参照先の業務モジュール)。
     */
    private static function groupOf(RecordField $field): string
    {
        return $field->type->value.':'.($field->referenced_record_module_id ?? '');
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> 受ける相手の id
     */
    private function acceptedIds(RecordField $field, array $ids, ReferenceCheck $check): array
    {
        return match ($field->type) {
            RecordFieldType::User => array_values(User::query()->whereKey($ids)->effectiveOn($check->date)->pluck('id')->all()),
            RecordFieldType::Department => array_values(Department::query()->whereKey($ids)->effectiveOn($check->date)->pluck('id')->all()),
            default => $this->readableRecordIds($field, $ids, $check),
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
        $module = $field->referencedRecordModule;

        if ($module === null || ! $this->gate->allows($check->requester, $module, RecordModuleAction::Read, $check->date)) {
            return [];
        }

        $records = Record::query()->where('record_module_id', $module->id)->whereKey($ids)->lock('for key share');

        return array_values($this->rows->constrain($records, $check->requester, $module, $check->date)->pluck('id')->all());
    }
}
