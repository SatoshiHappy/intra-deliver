<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Queries\Records\RecordDueField;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

/**
 * 期限が来たレコードの読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節)。
 * 主体の判定をしない(呼び手は業務モジュールの定期の処理)。
 */
class RecordDueQuery
{
    public function __construct(private RecordDueField $dueField) {}

    /**
     * @return list<int> 業務モジュールの親の無いレコードのうち、日付の項目の値が d の Asia/Tokyo の日付より前のものの id(昇順)。
     *                   値の無いレコードは入らない
     *
     * @throws LogicException 業務モジュールが無い・明細のモジュール・項目が無い・項目が日付の型でないとき(作りの誤り)
     */
    public function idsBefore(string $moduleCode, string $fieldCode, DateTimeInterface $date): array
    {
        $field = $this->dueField->field($this->dueField->module($moduleCode), $fieldCode);
        $day = CarbonImmutable::instance($date)->setTimezone(AccessContext::TIMEZONE)->toDateString();

        return array_values(Record::query()
            ->where('record_module_id', $field->record_module_id)
            ->whereNull('parent_record_id')
            ->whereRaw('(records.field_values ->> ?)::date < CAST(? AS date)', [(string) $field->id, $day])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id) => intval($id))
            ->all());
    }
}
