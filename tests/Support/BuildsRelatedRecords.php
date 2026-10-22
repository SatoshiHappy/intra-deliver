<?php

namespace Tests\Support;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;

/**
 * 関連レコードのテストのデータ(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 9 節): 参照先 projects(表示の項目 title)と、
 * 参照元 estimates(表示・一覧の項目 title、他モジュール参照 customer・billing、明細 lines の他モジュール参照 product)・issues(他モジュール参照
 * project)。業務モジュールの並びは estimates → issues、項目の並びは customer → billing → lines。名前はコードと同じ(明細の項目 lines の名前も)。
 *
 * @phpstan-type RelatedFixture array{projects: RecordModule, estimates: RecordModule, issues: RecordModule,
 *     customer: RecordField, billing: RecordField, lines: RecordField, product: RecordField, project: RecordField}
 */
trait BuildsRelatedRecords
{
    use BuildsRecordModules;

    /**
     * @param  array<string, list<RecordModuleAction>>  $permitted  業務モジュールのコード → ゲストと認証済みの全員に許すアクション(無ければ初期ポリシーの 4 つ)
     * @return RelatedFixture
     */
    protected function relatedFixture(array $permitted = []): array
    {
        $projects = $this->recordModule('projects', $permitted['projects'] ?? null);
        $this->field($projects, 'title', RecordFieldType::String, ['is_title' => true, 'is_listed' => true]);
        $estimates = $this->recordModule('estimates', $permitted['estimates'] ?? null);
        $estimates->update(['sort_key' => 1]);
        $this->field($estimates, 'title', RecordFieldType::String, ['is_title' => true, 'is_listed' => true]);
        $customer = $this->referenceField($estimates, 'customer', $projects, ['sort_key' => 10]);
        $billing = $this->referenceField($estimates, 'billing', $projects, ['sort_key' => 20]);
        $lines = $this->detailsField($estimates, 'lines', ['sort_key' => 30]);
        $product = $this->referenceField($this->childOf($lines), 'product', $projects);
        $issues = $this->recordModule('issues', $permitted['issues'] ?? null);
        $issues->update(['sort_key' => 2]);
        $project = $this->referenceField($issues, 'project', $projects);

        return [
            'projects' => $projects->refresh(),
            'estimates' => $estimates->refresh(),
            'issues' => $issues->refresh(),
            'customer' => $customer,
            'billing' => $billing,
            'lines' => $lines,
            'product' => $product,
            'project' => $project,
        ];
    }

    /**
     * 親の無いレコード(値 = 項目のコード → 値。参照の値は書かない = {@see self::refer()})。
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $attributes
     */
    protected function recordIn(RecordModule $module, array $values = [], array $attributes = []): Record
    {
        $ids = RecordField::query()->where('record_module_id', $module->id)->pluck('id', 'code');
        $fieldValues = [];

        foreach ($values as $code => $value) {
            $fieldValues[(string) $ids[$code]] = $value;
        }

        return Record::factory()->create([
            'record_module_id' => $module->id,
            'field_values' => $fieldValues,
            ...$attributes,
        ]);
    }

    /**
     * 明細の行(明細の項目の明細のモジュールの、親を持つレコード)。
     */
    protected function detailRow(RecordField $detailsField, Record $parent): Record
    {
        return Record::factory()->create([
            'record_module_id' => $detailsField->child_record_module_id,
            'parent_record_module_id' => $parent->record_module_id,
            'parent_record_id' => $parent->id,
        ]);
    }

    /**
     * 他モジュール参照の値の行(レコードの項目が相手を指す)。
     */
    protected function refer(Record $record, RecordField $field, Record $target): void
    {
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            'referenced_record_module_id' => $target->record_module_id,
            'referenced_record_id' => $target->id,
        ]);
    }
}
