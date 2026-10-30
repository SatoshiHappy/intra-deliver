<?php

namespace Tests\Support;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordFieldType;
use App\Models\Master\DepartmentSet;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordReference;

/**
 * 型「ユーザーと組織」の書き込みのテストの手助け: 業務モジュール meetings(項目 title・host = 1 つ・parties = 複数)を作り、
 * 相手の入力({type, id})で登録・更新し、項目の参照の行を読む。使うクラスは BuildsCommonMaster と BuildsRecordModules も使う。
 */
trait WritesUserOrDepartmentFields
{
    use BuildsPartyInputs;

    private const RETIRED = [['2020-01-01', '2026-04-01']];

    private DepartmentSet $set;

    /** @var array<string, RecordField> コード → 業務モジュール meetings の型「ユーザーと組織」の項目 */
    private array $fields;

    /**
     * 業務モジュール meetings と、その型「ユーザーと組織」の項目(host = 1 つ・parties = 複数)と、組織の版の集合を作る。
     */
    private function setUpPartyFields(): void
    {
        $module = $this->recordModule('meetings');
        $this->field($module, 'title');
        $this->fields = [
            'host' => $this->field($module, 'host', RecordFieldType::UserOrDepartment),
            'parties' => $this->field($module, 'parties', RecordFieldType::UserOrDepartment, ['is_multiple' => true]),
        ];
        $this->set = DepartmentSet::factory()->create();
    }

    /**
     * 項目の参照の行(相手の種類・id と sort_key。sort_key の順)。
     *
     * @return list<array{string, int, int}>
     */
    private function rows(Record $record, string $code): array
    {
        return array_values(RecordReference::query()
            ->where('record_id', $record->id)
            ->where('record_field_id', $this->fields[$code]->id)
            ->orderBy('sort_key')
            ->get()
            ->map(fn (RecordReference $row) => [...$row->party(), 'sort_key' => $row->sort_key])
            ->map(fn (array $row) => [$row['type'], $row['id'], $row['sort_key']])
            ->all());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): Record
    {
        $input = new RecordInput(['title' => '会議', ...$values]);
        $id = app(CreateRecord::class)->execute($this->contextFor(), 'meetings', $input);

        return Record::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(Record $record, array $values): void
    {
        $version = Record::query()->findOrFail($record->id)->version();

        $input = new RecordInput($values);
        app(UpdateRecord::class)->execute($this->contextFor(), 'meetings', $record->id, $input, $version);
    }
}
