<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Records\RecordFieldType;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 申請対象の値の写しの写す時の確かめ(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.1 節・11 節の
 * 10.1 の写す時の確かめ): 写した値に申請書の項目の宣言で写しの初期値の口「値から」を当て、今日に有効でない人・読めない参照先・申請書の項目に無い
 * 選択のコードを空にして clearedFields に名前を返す。写す先の申請書のレコードがあれば(そのものを申請する・再申請)、それを指す値と明細の行の値も
 * 空にし、新規では空にしない。
 */
final class TargetValueClearingTest extends TransactionalTestCase
{
    use BuildsApplicationTargetCopies;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    public function test_unusable_values_are_cleared_and_named_in_the_order_of_the_form(): void
    {
        [$u0001, $u0003] = $this->usersOf('u0001', 'u0003');
        $this->copyFlow([$u0001]);
        $this->bothFields('person', RecordFieldType::User);
        $ids = $this->hubRecord($u0001, $u0003);
        $person = $this->user([['2020-01-01', $this->operationDay()->toDateString(), false]]);
        $this->referTo($ids['hub'], 'person', 'user_id', $person->id);
        $this->setChoice($ids['hub'], 'b');
        $formFields = RecordField::query()->where('record_module_id', $this->moduleId(self::COPY_FORM))->select('id');
        RecordFieldOption::query()->where('code', 'b')->whereIn('record_field_id', $formFields)->delete();
        $this->onlyRelated('m_ref_a');
        $this->actingAs($u0001);

        $copied = $this->getJson($this->valuesUrl($ids['hub'], ['flow' => self::COPY_FLOW]))->assertOk()->json();

        $values = $copied['values'];
        $this->assertSame([null, null, null], [$values['kind'], $values['ref'], $values['person']]);
        $this->assertSame(['kind', 'ref', 'person'], array_column($copied['clearedFields'], 'name'));
        $this->assertSame('プロジェクトX', $copied['values'][self::TARGET_FIELD]['label']);
    }

    public function test_values_pointing_to_the_written_record_are_cleared_only_when_there_is_one(): void
    {
        [$u0001] = $this->usersOf('u0001');
        $this->copyFlow([$u0001]);
        $this->bothFields('back', RecordFieldType::Record);
        $this->bothFields('back_row', RecordFieldType::Record, '_lines');
        $hub = $this->hubRecord($u0001)['hub'];
        $form = $this->copyFormRecord($u0001, $hub);
        $case = $this->applyCopy($u0001);
        $this->actingAs($u0001);
        $writtenBy = [
            $form => ['flow' => self::COPY_FLOW, 'record' => (string) $form],
            (int) $case->record_id => ['case' => (string) $case->id],
        ];

        foreach ($writtenBy as $written => $query) {
            $this->pointTo($hub, $written);
            $toWritten = $this->getJson($this->valuesUrl($hub, $query))->assertOk()->json();
            $toNew = $this->getJson($this->valuesUrl($hub, ['flow' => self::COPY_FLOW]))->assertOk()->json();

            $this->assertNull($toWritten['values']['back']);
            $this->assertNull($toWritten['details']['lines'][0]['values']['back_row']);
            $this->assertSame(['back', 'linesのback_row'], array_column($toWritten['clearedFields'], 'name'));
            $this->assertSame($written, $toNew['values']['back']['id']);
            $this->assertSame([], array_column($toNew['clearedFields'], 'name'));
        }

        $this->pointTo($hub, $form);
        $response = $this->get('/workflow/flows/'.self::COPY_FLOW."/apply?record={$form}")->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame(['back', 'linesのback_row'], array_column($props['targetValues']['clearedFields'], 'name'));
    }

    /**
     * 申請対象(m_hub)と申請書(m_copy)の両方に同じ形の項目を足す(写しの規則に当たる。$suffix = '_lines' なら明細のモジュールに)。
     * 他モジュール参照は申請書の業務モジュールを指す。
     */
    private function bothFields(string $code, RecordFieldType $type, string $suffix = ''): void
    {
        $referenced = $type === RecordFieldType::Record
            ? ['referenced_record_module_id' => $this->moduleId(self::COPY_FORM)]
            : [];

        foreach ([self::HUB, self::COPY_FORM] as $moduleCode) {
            $module = RecordModule::query()->findOrFail($this->moduleId($moduleCode.$suffix));
            $this->field($module, $code, $type, ['sort_key' => 1, ...$referenced]);
        }
    }

    /**
     * 申請対象の back と、最初の明細の行の back_row を、申請書のレコードに向け直す。
     */
    private function pointTo(int $hub, int $written): void
    {
        $row = Record::query()->where('parent_record_id', $hub)->orderBy('sort_key')->orderBy('id')->firstOrFail()->id;
        RecordReference::query()->whereIn('record_id', [$hub, $row])
            ->whereIn('record_field_id', RecordField::query()->whereIn('code', ['back', 'back_row'])->select('id'))
            ->delete();
        $this->referTo($hub, 'back', 'referenced_record_id', $written);
        $this->referTo($row, 'back_row', 'referenced_record_id', $written);
    }

    private function referTo(int $recordId, string $code, string $column, int $targetId): void
    {
        $record = Record::query()->findOrFail($recordId);
        $field = RecordField::query()->where('record_module_id', $record->record_module_id)
            ->where('code', $code)->sole();
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            $column => $targetId,
            'referenced_record_module_id' => $column === 'referenced_record_id'
                ? $field->referenced_record_module_id : null,
        ]);
    }

    private function setChoice(int $recordId, string $optionCode): void
    {
        $record = Record::query()->findOrFail($recordId);
        $kind = RecordField::query()->where('record_module_id', $record->record_module_id)
            ->where('code', 'kind')->sole();
        $values = $record->field_values ?? [];
        $values[$kind->id] = $optionCode;
        $record->update(['field_values' => $values]);
    }

    private function moduleId(string $code): int
    {
        return RecordModule::query()->where('code', $code)->sole()->id;
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2: bool}>  $terms
     */
    private function user(array $terms): User
    {
        $user = User::factory()->create();

        foreach ($terms as [$start, $end, $deleted]) {
            UserTerm::factory()->create([
                'user_id' => $user->id, 'start_date' => $start, 'end_date' => $end, 'is_deleted' => $deleted,
            ]);
        }

        return $user;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function valuesUrl(int $target, array $query): string
    {
        return "/workflow/target-values/{$target}?".http_build_query($query);
    }
}
