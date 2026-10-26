<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 他モジュール参照の表示名(内部。{@see RecordValueReader} から分けた、参照先のレコードの表示の項目の値を判定する者で出し分ける部分 =
 * docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4 の「参照の表示名」)。判定する者が無ければ表示名を引かない
 * (主体の判定をしない読み取り = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表)。
 *
 * @phpstan-import-type ReferenceValue from RecordValueReader
 */
class ReferencedRecordLabels
{
    public function __construct(
        private RecordModuleGate $gate,
        private FieldValueParser $parser,
        private RecordDefinitionQuery $definitions,
        private RecordRowAccess $rows,
    ) {}

    /**
     * 表示の項目の値(表示の項目が無い・値が文字列でなければ null)。
     */
    public function title(Record $record, ?RecordField $titleField): ?string
    {
        $value = $titleField === null ? null : $this->parser->stored($titleField, $record->field_values ?? []);

        return is_string($value) ? $value : null;
    }

    /**
     * 参照先のレコードの値(表示の項目の値。参照先の業務モジュールの参照を許されていないか、レコードが行の判定を通らないか、
     * 判定する者が無ければ null)。
     *
     * @param  list<int>  $recordIds
     * @return array<int, ReferenceValue>
     */
    public function of(array $recordIds, ?Requester $requester, DateTimeInterface $date): array
    {
        $values = [];

        $grouped = Record::query()->whereKey($recordIds)->get()->groupBy('record_module_id');
        $modules = RecordModule::query()->with(['recordFields' => fn ($fields) => $fields->where('is_title', true)])
            ->whereKey($grouped->keys()->all())->get()->keyBy('id');

        foreach ($grouped as $moduleId => $moduleRecords) {
            $module = $modules->get($moduleId)
                ?? throw (new ModelNotFoundException)->setModel(RecordModule::class, [$moduleId]);
            $labels = $requester === null ? [] : $this->readableTitles($requester, $module, $moduleRecords, $date);

            foreach ($moduleRecords as $record) {
                $label = $labels[$record->id] ?? null;
                $values[$record->id] = ['id' => $record->id, 'code' => null, 'label' => $label, 'affiliation' => null];
            }
        }

        return $values;
    }

    /**
     * 判定する者が読める参照先のレコードの表示の項目の値(id → 値。業務モジュールの参照が許されず、行の判定を通らないレコードは入らない)。
     *
     * @param  Collection<int, Record>  $records  同じ業務モジュールのレコード
     * @return array<int, string|null>
     */
    private function readableTitles(
        Requester $requester,
        RecordModule $module,
        Collection $records,
        DateTimeInterface $date,
    ): array {
        $title = $this->gate->allows($requester, $module, RecordModuleAction::Read, $date)
            ? $this->definitions->titleFieldOf($module->recordFields) : null;

        if ($title === null) {
            return [];
        }

        $accessible = $this->rowAccessibleIds($requester, $module, $records, $date);
        $titles = [];

        foreach ($records as $record) {
            if (isset($accessible[$record->id])) {
                $titles[$record->id] = $this->title($record, $title);
            }
        }

        return $titles;
    }

    /**
     * 参照先の業務モジュールのレコードのうち、行の判定を通るもの(id → 真)。
     *
     * @param  Collection<int, Record>  $records
     * @return array<int, true>
     */
    private function rowAccessibleIds(
        Requester $requester,
        RecordModule $module,
        Collection $records,
        DateTimeInterface $date,
    ): array {
        $query = Record::query()->where('record_module_id', $module->id)->whereKey($records->modelKeys());

        return array_fill_keys($this->rows->constrain($query, $requester, $module, $date)->pluck('id')->all(), true);
    }
}
