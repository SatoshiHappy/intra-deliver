<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\DeleteRecordField;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\CopiesCases;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の前に消した項目の写し(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 13.2 の
 * 通常の筋): 値を入れたレコードの項目を消し(キーは値に残る)、そのレコードをそのものを申請した案件を写す。
 */
final class CaseCopyDeletedFieldsTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use CopiesCases;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 消した項目と、明細の行の中の消した項目は写らない(明細の行は並びのまま残り、消した項目のキーは初期値にも送る値にも入らない)。
     * 写した値で申請できる。
     */
    public function test_deleted_fields_and_deleted_row_fields_are_not_copied(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $formId = $this->formWithRows($u0001);
        app(DeleteRecordField::class)->execute(self::COPY_FORM, 'note');
        app(DeleteRecordField::class)->execute(self::COPY_FORM.'_lines', 'memo');
        $source = $this->applyRecord($u0001, null, $formId, []);

        $props = $this->copyPropsAs($u0001, $source->id);

        $this->assertArrayNotHasKey('note', $props['initial']['values']);
        $this->assertSame(7, $props['initial']['values']['amount']);
        $rowValues = array_map(fn (array $row) => $row['values'], $props['initial']['details']['lines']);
        $expectedRows = [['item' => 'A', 'qty' => 1], ['item' => 'B', 'qty' => 2]];
        $this->assertSame($expectedRows, array_map(self::sorted(...), $rowValues));
        $this->assertSame([], array_column($props['copy']['clearedFields'], 'name'));
        $sent = $this->sentFrom($props, ['details' => $props['initial']['details']]);
        $this->assertNotSame($source->record_id, $this->postApply($u0001, $sent)->record_id);
    }

    /**
     * 型「明細」の項目そのものを消すと、その明細の行は写らない。
     */
    public function test_rows_of_a_deleted_details_field_are_not_copied(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $formId = $this->formWithRows($u0001);
        app(DeleteRecordField::class)->execute(self::COPY_FORM, 'lines');
        $source = $this->applyRecord($u0001, null, $formId, []);

        $props = $this->copyPropsAs($u0001, $source->id);

        $this->assertSame([], $props['initial']['details']['lines'] ?? []);
        $this->assertSame(7, $props['initial']['values']['amount']);
    }

    /**
     * 申請書のレコード(額・メモ・明細の 2 行)。
     */
    private function formWithRows(User $creator): int
    {
        $rows = [
            new RecordDetailRowInput(values: ['item' => 'A', 'qty' => 1, 'memo' => 1]),
            new RecordDetailRowInput(values: ['item' => 'B', 'qty' => 2, 'memo' => 2]),
        ];
        $input = new RecordInput(['title' => '元の題', 'amount' => 7, 'note' => 9], ['lines' => $rows]);

        return app(CreateRecord::class)->execute($this->contextFor($creator->id), self::COPY_FORM, $input);
    }
}
