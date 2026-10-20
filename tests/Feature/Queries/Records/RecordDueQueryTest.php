<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordDueQuery;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 期限が来たレコードの読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節): 日付の項目の値が
 * d の Asia/Tokyo の日付より前の、業務モジュールの親の無いレコード(昇順)。作りの誤りは LogicException。
 */
final class RecordDueQueryTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_records_dated_before_the_tokyo_day_are_due(): void
    {
        $module = $this->recordModule('meetings');
        $dueFieldId = $this->field($module, 'purge_on', RecordFieldType::Date)->id;
        $other = $this->recordModule('notes');
        $otherDueFieldId = $this->field($other, 'purge_on', RecordFieldType::Date)->id;
        $later = $this->record($module, [$dueFieldId => '2026-10-19']);
        $earlier = $this->record($module, [$dueFieldId => '2026-09-30']);
        $this->record($module, [$dueFieldId => '2026-10-20']);
        $this->record($module, [$dueFieldId => '2026-10-21']);
        $this->record($module, []);
        $this->record($other, [$otherDueFieldId => '2026-01-01']);

        $due = $this->due('meetings', 'purge_on', '2026-10-19T16:00:00Z');

        $this->assertSame([$later, $earlier], $due);
        $this->assertSame([$earlier], $this->due('meetings', 'purge_on', '2026-10-19T14:59:59Z'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function designErrors(): array
    {
        return [
            '業務モジュールが無い' => ['missing', 'purge_on'],
            '明細のモジュール' => ['rows_rows', 'purge_on'],
            '項目が無い' => ['meetings', 'missing'],
            '日時の型' => ['meetings', 'held_at'],
            '文字列の型' => ['meetings', 'title'],
        ];
    }

    #[DataProvider('designErrors')]
    public function test_a_design_error_is_refused(string $moduleCode, string $fieldCode): void
    {
        $module = $this->recordModule('meetings');
        $this->field($module, 'purge_on', RecordFieldType::Date);
        $this->field($module, 'held_at', RecordFieldType::Datetime);
        $this->field($module, 'title');
        $this->field($this->childOf($this->detailsField($module, 'rows')), 'purge_on', RecordFieldType::Date);

        $this->expectException(LogicException::class);
        $this->due($moduleCode, $fieldCode, '2026-10-20T00:00:00Z');
    }

    /**
     * @param  array<int, string>  $values
     */
    private function record(RecordModule $module, array $values): int
    {
        return Record::factory()->create(['record_module_id' => $module->id, 'field_values' => $values])->id;
    }

    /**
     * @return list<int>
     */
    private function due(string $moduleCode, string $fieldCode, string $at): array
    {
        return app(RecordDueQuery::class)->idsBefore($moduleCode, $fieldCode, CarbonImmutable::parse($at));
    }
}
