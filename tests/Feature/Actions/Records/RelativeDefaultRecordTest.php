<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RelativeDefault;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 相対の既定値を登録の業務処理が値にする(docs の 設計/業務データの入れ物/項目と規則/README.md 3 節、
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「既定値」)。時計を止めて、登録の時点から値を作る。
 */
final class RelativeDefaultRecordTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    /**
     * 日付は登録の時点の Asia/Tokyo の日に期間を足す(UTC で前日の時刻でも東京の日)。月・年を足して日が無ければその月の末日。
     */
    #[TestWith(['2026-10-10 15:30:45 UTC', 'P0D', '2026-10-11'])]
    #[TestWith(['2026-10-10 15:30:45 UTC', 'P30D', '2026-11-10'])]
    #[TestWith(['2026-10-10 15:30:45 UTC', 'P6M', '2027-04-11'])]
    #[TestWith(['2026-10-10 15:30:45 UTC', 'P1Y', '2027-10-11'])]
    #[TestWith(['2026-08-31 03:00:00 UTC', 'P6M', '2027-02-28'])]
    #[TestWith(['2027-08-31 03:00:00 UTC', 'P6M', '2028-02-29'])]
    #[TestWith(['2026-10-10 15:30:45 UTC', 'P999Y', '3025-10-11'])]
    public function test_a_date_is_the_tokyo_day_plus_the_duration(string $now, string $duration, string $day): void
    {
        CarbonImmutable::setTestNow($now);
        $field = $this->relativeField($this->recordModule('meetings'), 'held_on', RecordFieldType::Date, $duration);

        $record = $this->create('meetings', []);

        $this->assertSame([$field->id => $day], $record->refresh()->field_values);
    }

    /**
     * 日時は登録の時刻の分まで(秒は 0)に期間を足し、UTC で持つ。
     */
    #[TestWith(['P0D', '2026-10-11T00:30:00Z'])]
    #[TestWith(['P6M', '2027-04-11T00:30:00Z'])]
    public function test_a_datetime_is_the_minute_plus_the_duration(string $duration, string $expected): void
    {
        CarbonImmutable::setTestNow('2026-10-11 00:30:45.123456 UTC');
        $module = $this->recordModule('meetings');
        $field = $this->relativeField($module, 'starts_at', RecordFieldType::Datetime, $duration);

        $record = $this->create('meetings', []);

        $this->assertSame([$field->id => $expected], $record->refresh()->field_values);
    }

    public function test_the_parent_and_the_detail_rows_read_the_clock_once(): void
    {
        $calls = 0;
        // 読むたびに 1 日進む時計(2 回読めば親と明細の行の値が分かれる)
        CarbonImmutable::setTestNow(function () use (&$calls) {
            $calls++;

            return CarbonImmutable::parse('2026-10-11 03:00:00 UTC')->addDays($calls);
        });
        $module = $this->recordModule('meetings');
        $heldOn = $this->relativeField($module, 'held_on', RecordFieldType::Date, 'P0D');
        $child = $this->childOf($this->detailsField($module, 'agenda'));
        $dueOn = $this->relativeField($child, 'due_on', RecordFieldType::Date, 'P0D');
        $title = $this->field($child, 'title');

        $record = $this->create('meetings', [], ['agenda' => [new RecordDetailRowInput(values: ['title' => '一'])]]);
        $row = Record::query()->where('parent_record_id', $record->id)->sole();

        $parentDay = $record->refresh()->field_values[$heldOn->id] ?? null;
        $this->assertIsString($parentDay);
        $this->assertEquals([$title->id => '一', $dueOn->id => $parentDay], $row->field_values);
    }

    public function test_fills_only_fields_without_a_key_and_not_on_update(): void
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $module = $this->recordModule('meetings');
        $heldOn = $this->relativeField($module, 'held_on', RecordFieldType::Date, 'P30D');
        $endsOn = $this->relativeField($module, 'ends_on', RecordFieldType::Date, 'P30D');
        $purgeOn = $this->relativeField($module, 'purge_on', RecordFieldType::Date, 'P6M');
        $published = $this->field($module, 'published', RecordFieldType::Boolean);

        $record = $this->create('meetings', ['held_on' => '2026-12-01', 'ends_on' => null]);
        $this->assertEquals(
            [$heldOn->id => '2026-12-01', $purgeOn->id => '2027-04-11', $published->id => false],
            $record->refresh()->field_values,
        );

        $later = $this->relativeField($module, 'later_on', RecordFieldType::Date, 'P0D');
        $input = new RecordInput(['purge_on' => null], []);
        app(UpdateRecord::class)->execute($this->contextFor(), 'meetings', $record->id, $input, $record->version());

        $this->assertEquals([$heldOn->id => '2026-12-01', $published->id => false], $record->refresh()->field_values);
        $this->assertArrayNotHasKey($endsOn->id, $record->field_values ?? []);
        $this->assertArrayNotHasKey($later->id, $record->field_values ?? []);
    }

    /**
     * 相対の既定値を持つ日付・日時の項目。
     */
    private function relativeField(
        RecordModule $module,
        string $code,
        RecordFieldType $type,
        string $duration,
    ): RecordField {
        return $this->field($module, $code, $type, ['default_value' => RelativeDefault::tryFromDuration($duration)]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     */
    private function create(string $moduleCode, array $values, array $details = []): Record
    {
        $id = app(CreateRecord::class)->execute($this->contextFor(), $moduleCode, new RecordInput($values, $details));

        return Record::query()->findOrFail($id);
    }
}
